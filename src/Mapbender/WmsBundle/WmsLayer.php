<?php

namespace Mapbender\WmsBundle;

use Mapbender\CoreBundle\Component\LayerInterface;

/**
 * Base WMS class
 *
 * @author Christian Wygoda <christian.wygoda@wheregroup.com>
 */
class WmsLayer implements LayerInterface {
    protected $id;
    protected $configuration;

    public function __construct($id, array $configuration) {
        $this->id = $id;
        $this->configuration = $configuration;
    }

    public function render() {
        return array(
            'id' => $this->id,
            'type' => 'wms',
            'configuration' => $this->configuration,
        );
    }

    public function getAssets() {
        return array(
            'js' => array(
                'mapbender.layer.wms.js'
            )
        );
    }
}

