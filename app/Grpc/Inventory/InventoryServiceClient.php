<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Inventory;

/**
 */
class InventoryServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Inventory\GetStockRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetStock(\Inventory\GetStockRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/GetStock',
        $argument,
        ['\Inventory\GetStockResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Inventory\ReserveStockRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ReserveStock(\Inventory\ReserveStockRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/ReserveStock',
        $argument,
        ['\Inventory\ReserveStockResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Inventory\ReleaseStockRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ReleaseStock(\Inventory\ReleaseStockRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/ReleaseStock',
        $argument,
        ['\Inventory\ReleaseStockResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Inventory\SetStockRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SetStock(\Inventory\SetStockRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/inventory.InventoryService/SetStock',
        $argument,
        ['\Inventory\SetStockResponse', 'decode'],
        $metadata, $options);
    }

}
