<?php

namespace gcf\data;

use gcf\cache\cachePlugin;
use gcf\ConfiguratorBase;
use gcf\database\errorDriverDB;
use gcf\database\models\DataMapper;
use gcf\database\SQLQuery;
use gcf\Environment;
use Laminas\Log\Logger;
use stdClass;

abstract class QueryBuilderBase implements QueryBuilderInterface
{
    protected ?DataMapper $tbl = null;

    protected StdClass $filtres;

    protected ConfiguratorBase $configurador;

    protected cachePlugin $cache;

    protected ?Logger $logger;

    public array $params = [];

    /**
     * @throws \Exception
     */
    public function __construct(StdClass $filtres)
    {
        $this->filtres = $filtres;
        $this->configurador = Environment::getInstance()->GetAppConfigurator();
        $this->cache = $this->configurador->getCache();
        $this->logger = $this->configurador->getLoggerObject();
    }

    /**
     * Execute and return consulta_sql object
     * @throws errorDriverDB
     */
    protected function Execute(): SQLQuery
    {
        $q = new SQLQuery($this->configurador->db);
        $sql = $this->PreparedQuery();
        $q->PrepareQuery($sql, params: $this->params);
        return $q;
    }

    protected function getParams() : array {
        return $this->params;

    }
}