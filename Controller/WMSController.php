<?php

namespace MB\WMSBundle\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use MB\WMSBundle\Entity\WMSService;
use MB\WMSBundle\Entity\WMSLayer;
use MB\WMSBundle\Entity\GroupLayer;
use MB\WMSBundle\Components\CapabilitiesParser;
use MB\WMSBundle\Form\WMSType;

/*
* @package bkg
* @author Karim Malhas <karim@malhas.de>
*/
class WMSController extends Controller {
    
    /**
     * Shows the startpage of the WMS Bundle
     * @Route("/")
     * @Method({"GET"})
     * @Template()
    */
    public function indexAction(){

        $request = $this->get('request');
        $first = $request->get('first') ? $request->get('first') : 0;
        $max = $request->get('max') ? $request->get('max') : 10;
        // allow 1000 results per page
        $max = $max < 1000 ? $max : 1000;

        $em = $this->get("doctrine.orm.entity_manager");
        $q = $em->createQuery("select wms from MB\WMSBundle\Entity\WMSService wms ");
        $q->setFirstResult($first);
        $q->setMaxResults($max);
        $wmsArr = $q->getResult();

        $nextFirst = count($wmsArr) < $max ? $first : $first + $max;
        $prevFirst = ($first - $max)  > 0 ? $first - $max : 0;
        return array(
            "wmsArr" => $wmsArr,
            "nextFirst" =>  $nextFirst,
            "prevFirst" => $prevFirst,
            "max" => $max
        );
    }


    /**
     * shows the dialog that allows adding a WMS
     * @Route("/add")
     * @Method({"GET"})
     * @Template()
    */
    public function registerAction(){
        return array();
    }
    
    /**
     * shows preview of WMS
     * @Route("/preview")
     * @Method({"POST"})
     * @Template()
    */
    public function previewAction(){
        $getcapa_url = $this->get('request')->request->get('getcapa_url');
        if(!$getcapa_url){
            throw new \Exception('getcapa_url not set');
        }
        

        $ch = curl_init($getcapa_url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        try {
            $proxyConf = $this->container->getParameter('proxy');
        }catch(\InvalidArgumentException $E){
            // thrown when the parameter is not set
            // maybe some logging ?
            $proxyConf = array();
        }
        if($proxyConf && isset($proxyConf['host']) && $proxyConf['host'] != ""){
            curl_setopt($ch, CURLOPT_PROXY,$proxyConf['host']);
            curl_setopt($ch, CURLOPT_PROXYPORT,$proxyConf['port']?:"");
        }

        $data = curl_exec($ch);

        if(!$data){
            $this->get("logger")->debug("$getcapa_url returned no data");
            throw new \Exception('Service returned no Data');
        }

        $capaParser = new CapabilitiesParser($data);

        $wms = $capaParser->getWMSService();
        if(!$wms){
            throw new \Exception("could not parse data for url '$getcapa_url'");
        }
    

        $form = $this->get('form.factory')->create(new WMSType(), $wms,array(
            "exceptionFormats" => $wms->getExceptionFormats(),
            "requestGetCapabilitiesFormats" => $wms->getRequestGetCapabilitiesFormats(),
            "requestGetMapFormats" => $wms->getRequestGetMapFormats(),
            "requestGetFeatureInfoFormats" => $wms->getRequestGetFeatureInfoFormats(),
            "requestDescribeLayerFormats"  => $wms->getRequestDescribeLayerFormats(),
            "requestGetLegendGraphicFormats" => $wms->getRequestGetLegendGraphicFormats(),
            "requestGetStylesFormats" => $wms->getRequestGetStylesFormats(),
            "requestPutStylesFormats" => $wms->getRequestPutStylesFormats(),
        ));
        
        $bounds = $wms->getRootLayer()->getLatLonBounds();
        $b = explode(" ",$bounds);
        return array(
                "getcapa_url"=>$getcapa_url,
                "wms" => $wms,
                "form" => $form->createView(),
                "xml" => $data,
                "minx" => isset($b[0])?$b[0]:"",
                "miny" => isset($b[1])?$b[1]:"",
                "maxx" => isset($b[2])?$b[2]:"",
                "maxy" => isset($b[3])?$b[3]:""
            );
    }

    /**
     * adds a WMS
     * @Route("/")
     * @Method({"POST"})
    */
    public function addAction(){

        $request = $this->get('request');

        $requestWMS = $request->get('WMSService');
        $wms = new WMSService();
        $wms = $this->buildWMSFormStructure($wms,$requestWMS);
        $form = $this->get('form.factory')->create(new WMSType(),$wms); 
        $form->bindRequest($request);
    
        if($form->isValid()){
            $em = $this->get("doctrine.orm.entity_manager");
            $this->persistRecursive($wms,$em);
            $em->persist($wms);
            $em->flush();
            return $this->redirect($this->generateUrl("mb_wms_wms_index",
                array("info_message" =>"WMS '".$wms. "' saved"),
            true));
        }else{
            // FIXME: getcapa_url is missing, xml is missing
            return $this->render("MBWMSBundle:WMS:preview.html.twig",array(
                    "getcapa_url"=> "",
                    "wms" => $wms,
                    "form" => $form->createView(),
                    "xml" =>""
                ));
        }
    
        
    }

    /**
     * Shows the WMS in an Editor
     * @Route("/{id}"))
     * @Method({"GET"})
     * @Template()
    */
    public function editAction(WMSService $wms){
        $form = $this->get('form.factory')->create(new WMSType(),$wms); 
        return array(
            "wms" => $wms,
            "form"  => $form->createView(),
        );
    }
    
    /**
     * Shows the WMS in an Editor
     * @Route("/{id}"))
     * @Method({"POST"})
     * @Template()
    */
    public function saveAction(WMSService $wms){
        $request = $this->get('request');
        /* build up nested wmslayer structure */
        $requestWMS = $request->get('WMSService');
        $form = $this->get('form.factory')->create(new WMSType(),$wms); 
        $form->bindRequest($request);
        $em = $this->get("doctrine.orm.entity_manager");
        $this->persistRecursive($wms,$em);
        return $this->redirect($this->generateUrl("mb_wms_wms_edit", array("id"=>$wms->getId())));
    }

    /**
     * shows the dialog for wms Deletion confirmation
     * @Route("/{id}/delete")
     * @Method({"GET"})
     * @Template()
    */
    public function confirmdeleteAction(WMSService $wms){
        return array(
               'wms' => $wms 
        );
    }

    /**
     * deletes a WMS
     * @Route("/{id}/delete")
     * @Method({"POST"})
    */
    public function deleteAction(WMSService $wms){
        $em = $this->getDoctrine()->getEntityManager();
        $em->remove($wms);
        $em->flush();
        return $this->redirect($this->generateUrl("mb_wms_wms_index"));
    }

    /**
     * FIXME: this can be removed
     * Shows the details of a WMS
     * @Route("/{id}/details")
     * @Method({"GET"})
     * @Template()
    */
    public function detailsAction(WMSService $wms){
        $bounds = $wms->getRootLayer()->getLatLonBounds();
        $b = explode(" ",$bounds);
        return array(
            "wms" => $wms,
            "minx" => isset($b[0])?$b[0]:"",
            "miny" => isset($b[1])?$b[1]:"",
            "maxx" => isset($b[2])?$b[2]:"",
            "maxy" => isset($b[3])?$b[3]:""
        );
    }
    

 
  /**
     * Recursively persists a nested Layerstructure
     * param GroupLayer
     * param EntityManager
    */
    public function persistRecursive($grouplayer,$em){
        $em->persist($grouplayer);
        if(count($grouplayer->getLayer()) > 0 ){
            foreach($grouplayer->getLayer() as $layer){
                $layer->setParent($grouplayer);
                $this->persistRecursive($layer,$em);
            }
        }
        $em->flush();
    }
    /**
     *  
     * Takes an Arraystructure from a POSTrequest and recurses into the nested layers to build a matching WMSLayer structure
     * So that a Form can be bound to the layer
     * param GroupLayer the rootlayer of the Layer hierarchy
     * param array POST request from a WMS structure
    */
    public function buildWMSFormStructure($grouplayer,array $grouplayerArr){
        if(isset($grouplayerArr['layer']) && is_array($grouplayerArr['layer'])){
                foreach($grouplayerArr['layer'] as $layerArr){
                        $layer = new WMSLayer();
                        if(isset($layerArr['layer']) && is_array($layerArr['layer']) && count($layerArr['layer'])){
                                $layer = $this->buildWMSFormStructure($layer, $layerArr);

                        }   
                        $grouplayer->addLayer($layer);
                }   
        }   
        return $grouplayer;
    } 

}
