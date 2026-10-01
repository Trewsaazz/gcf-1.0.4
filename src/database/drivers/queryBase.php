<?php

namespace gcf\database\drivers;

abstract class queryBase 
{
	protected dataBaseConn $dataBase;
    protected mixed $connDb;

	protected int $rowActual, $rows;
	protected $result;
	protected $error;
	protected false|string|null $blobID = false;

    public array $row = [];
    public bool $assoc = false;

    public string $query;

    public array $params = [];

	public function __construct(dataBaseConn $db)
	{
		$this->dataBase = $db;
		$this->connDb = $db->connDb;
		$this->rowActual = $this->rows = -1;
	}

    /**
     * @return mixed
     * @throws errorQuerySQL
     */
	abstract public function Execute() : mixed;

	abstract public function Skip();
	abstract public function NumFields();
	abstract public function Close();
	abstract public function BeginTrans();
    abstract public function Commit($idTrans);
    abstract public function Rollback($idTrans);
    abstract public function NextID($generatorID);

    // Field properties methods
    abstract public function GetFieldType($field);
    abstract public function GetFieldName($field);
    abstract public function GetFieldLength($field);
    abstract public function GetFieldRelation($field);

    // BLOB abstract methods
    abstract public function LoadFromBLOB($rowBlob);
    abstract protected function StoreBLOB($fileDescriptor);

    public function GetConn() : dataBaseConn
    {
           return($this->connDb);
    }

    public function ChangeConnection(dataBaseConn $connDb) : void
    {
           $this->connDb = $connDb;
    }

    /**
     * @param $queryStr
     * @return mixed
     * @throws errorQuerySQL
     */
	public function Query($queryStr) : mixed
	{
		$this->query = $queryStr;
		return $this->Execute();
	}
	
    public function Go($numRow) : void
    {
           if ($this->rowActual == $numRow) return;
           $this->rowActual = $numRow - 1;
           $i = 0;
	       while($i < $numRow) 
	       {
		         $i++;
                 $this->Skip();
	       }
    }

    public function Eof() : bool
    {
           return($this->rows == 0 || $this->rowActual >= $this->rows);
    }

    public function Record() : int
    {
           return $this->rowActual;
    }
	
    public function LastRecord() : int
    {
           return $this->rows;
    }

    public function LastError() : string
    {
            return $this->error;
    }

    public function StoreFileToBLOB(string $nomFitxer) : bool
    {
           if(!file_exists($nomFitxer)) 
              return false;

           $fd = fopen($nomFitxer,"r");
           $this->blobID = $this->StoreBLOB($fd);
	       fclose($fd);

           return true;
	}

    public function __toString()
    {
           return $this->query;
    }

	public function __destruct()
	{
           $this->Close();
	}

}
