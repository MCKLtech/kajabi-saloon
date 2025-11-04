<?php

namespace WooNinja\KajabiSaloon\Interfaces;

use WooNinja\KajabiSaloon\Connectors\KajabiConnector;

interface Kajabi
{
    public function connector(): KajabiConnector;
}
