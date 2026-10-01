<?php
namespace gcf\database;

use gcf\cache\cachePlugin;
use gcf\database\drivers\errorQuerySQL;
use gcf\database\drivers\queryBase;
use stdClass;

class SQLQuery
{
    const DEFAULT_CACHED_QUERY_EXPIRE = 3600;

    /**
     * Query object
     * @var queryBase
     */
    private queryBase $consulta;

    /**
     * @var ?cachePlugin
     */
    private ?cachePlugin $cache = null;

    private bool $autoFlush;

    private stdClass $queryObj;

    public readonly string $firmaLastQuery;

    private int $rowCount;

    public mixed $row;

    /**
     * @var bool
     */
    private bool $initialGet;

    /**
     * consulta_sql constructor.
     * @param DatabaseConnector $db
     * @param cachePlugin|null $cache
     * @param bool $autoFlush
     * @throws errorDriverDB
     */
    public function __construct(DatabaseConnector $db, ?cachePlugin $cache = null, bool $autoFlush = false)
    {
        $className = "gcf\\database\\drivers\\$db->drv\\QuerySQL";
        if (!class_exists($className))
            throw new errorDriverDB("No trob el driver $className");

        $this->consulta = new $className($db->dataBase);

        if (!empty($cache))
            $this->cache = $cache;

        $this->autoFlush = $autoFlush;
        $this->rowCount = -1;
        $this->initialGet = false;
    }

    private function extractFieldTypes(): array
    {
        $rowTypes = [];
        for ($i = 0; $i < count($this->consulta->row); $i++) {
            $rowTypes[] = ["TYPE" => $this->consulta->GetFieldType($i),
                           "NAME" => $this->consulta->GetFieldName($i),
                           "LENGTH" => $this->consulta->GetFieldLength($i),
                           "RELATION" => $this->consulta->GetFieldRelation($i)];
        }
        return $rowTypes;
    }

    public function PrepareQuery(string $query, bool $assoc=true, ?array $params=null) : void
    {
        $this->consulta->assoc = $assoc;
        $this->consulta->query = $query;
        $this->consulta->params = $params ?? [];
    }

    /**
     * @param string $query
     * @param bool $assoc
     * @return mixed
     *
     * @throws errorQuerySQL
     */
    public function fer_consulta(string $query, bool $assoc=false, ?array $params=null) : mixed
    {
        $params ??= [];
        $this->consulta->assoc = $assoc;
        $this->consulta->params = $params;
        $resCons = null;

        if (isset($this->cache))
        {
            $objectName = "G";
            if (preg_match("/^[ \n\r\t]*(select|SELECT).+(from|FROM)[ \n\r\t]*([a-zA-Z0-9_]+)[ \n\r\t]*/", $query, $queryParts)) {
                $objectName = $queryParts[3];
            }
            $this->firmaLastQuery = "QUERY:$objectName:".sha1($query . ($params ? serialize($params) : ''));
            $cachedQuery = $this->cache->get($this->firmaLastQuery);
            if ($cachedQuery === false)
            {
                $this->initialGet = true;
                $resCons = $this->consulta->Query($query);
                $queryObj = new stdClass;
                $queryObj->consulta = $this->consulta;
                $queryObj->rowTypes = $this->extractFieldTypes();
                $queryObj->allRows = [ $this->consulta->row ];
                $this->cache->set($this->firmaLastQuery, $queryObj, self::DEFAULT_CACHED_QUERY_EXPIRE);
                $this->queryObj = $queryObj;
            } else {
                unset($this->consulta);
                $this->queryObj = $cachedQuery;
                $this->consulta = $this->queryObj->consulta;
                $this->consulta->row = $this->queryObj->allRows[++$this->rowCount];
            }
        } else
            $resCons = $this->consulta->Query($query);

        $this->row = $this->consulta->row;
        return $resCons;
    }

    /**
     * @param bool $assoc
     * @return mixed
     * @throws errorQuerySQL
     */
    public function executa(bool $assoc=false) : mixed
    {
        if (isset($this->consulta))
            return $this->fer_consulta($this->consulta->query, $assoc, $this->consulta->params);
        return false;
    }

    public function Eof() : bool
    {
        if (isset($this->cache))
        {
            if ($this->initialGet)
                return $this->consulta->Eof();

            if ($this->rowCount < count($this->queryObj->allRows))
            {
                $this->row = $this->queryObj->allRows[$this->rowCount];
                return false;
            }
            return true;
        } else {
            $this->row = $this->consulta->row;
            return $this->consulta->Eof();
        }
    }

    public function Skip() : void
    {
        if (isset($this->cache))
        {
            if ($this->initialGet)
            {
                $this->consulta->Skip();
                if (!$this->consulta->Eof()) {
                    $this->queryObj->allRows[] = $this->consulta->row;
                }
                $this->row = $this->consulta->row;
            } else {
                $this->row = $this->queryObj->allRows[$this->rowCount++];
            }
        }
        else
        {
            $this->consulta->Skip();
            $this->row = $this->consulta->row;
        }
    }

    public function Record() : int
    {
        if (isset($this->cache))
        {
            if ($this->initialGet)
                return $this->consulta->Record();
            return ($this->rowCount);
        }

        return $this->consulta->Record();
    }

    public function LastRecord() : int
    {
        if (isset($this->cache))
        {
            if ($this->initialGet)
                return $this->consulta->LastRecord();
            return (count($this->queryObj->allRows));
        }

        return $this->consulta->LastRecord();
    }

    public function TipusField(int $numField)
    {
        if (isset($this->cache))
        {
            if ($numField>count($this->queryObj->rowTypes))
                return null;
            return $this->queryObj->rowTypes[$numField]["TYPE"];
        }

        return $this->consulta->GetFieldType($numField);
    }

    public function NomField(int $numField)
    {
        if (isset($this->cache))
        {
            if ($numField>count($this->queryObj->rowTypes)-1)
                return null;
            return $this->queryObj->rowTypes[$numField]["NAME"];
        }

        return $this->consulta->GetFieldName($numField);
    }

    public function LenField(int $numField)
    {
        if (isset($this->cache))
        {
            if ($numField>count($this->queryObj->rowTypes)-1)
                return null;
            return $this->queryObj->rowTypes[$numField]["LENGTH"];
        }

        return $this->consulta->GetFieldLength($numField);
    }

    public function RelacioField(int $numField)
    {
        if (isset($this->cache))
        {
            if ($numField>count($this->queryObj->rowTypes)-1)
                return null;
            return $this->queryObj->rowTypes[$numField]["RELATION"];
        }

        return $this->consulta->GetFieldRelation($numField);
    }

    /**
     * @return mixed
     */
    public function NumFields()
    {
        return $this->consulta->NumFields();
    }

    public function carregarBLOB($blobID)
    {
        return $this->consulta->LoadFromBLOB($blobID);
    }

    public function guardaImatge(string $fileName) : bool
    {
        return $this->consulta->StoreFileToBLOB($fileName);
    }

    public function iniciTrans()
    {
        return $this->consulta->BeginTrans();
    }

    public function ferRollback($idTrans=null)
    {
        return $this->consulta->Rollback($idTrans);
    }

    public function ferCommit($idTrans=null)
    {
        return $this->consulta->Commit($idTrans);
    }

    public function tanca_consulta() : void
    {
        if (isset($this->cache)) {
            if ($this->initialGet) {
                $this->cache->set($this->firmaLastQuery, $this->queryObj);
            }
        }
        $this->consulta->Close();
    }

    public function nextID($genID)
    {
        return $this->consulta->NextID($genID);
    }

    public function lastError()
    {
        return $this->consulta->LastError();
    }

    public function __destruct()
    {
        if (isset($this->cache) && !$this->initialGet && $this->autoFlush) {
            $this->cache->delete($this->firmaLastQuery);
        }
        $this->consulta->Close();
    }
}
