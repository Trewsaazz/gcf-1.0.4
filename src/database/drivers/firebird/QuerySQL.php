<?php
namespace gcf\database\drivers\firebird;

use gcf\database\drivers\dataBaseConn;
use gcf\database\drivers\errorQuerySQL;
use gcf\database\drivers\errorTransSQL;
use gcf\database\drivers\queryBase;

class QuerySQL extends queryBase
{
      private bool $myEof = false;

      private array $typesDefinition = [];

      protected mixed $hndTrans;

      public function __construct(dataBaseConn $db)
      {
             $this->hndTrans = null;
             parent::__construct($db);
      }

      final public function Eof() : bool
      {
            return($this->myEof);
      }
     
      public function Skip() : bool
      {
             $this->rowActual++;
             if(!$this->Eof()) 
             {
                $fetchFunc = $this->assoc ? "ibase_fetch_assoc" : "ibase_fetch_row";
                if(!($row = @$fetchFunc($this->result)))
                   $this->myEof = true;

                $this->row = ($row === false) ? [] : $row;

                return true;
             }
              
             return false;
      }

    /**
     * @return resource
     * @throws errorQuerySQL
     */
      public function Execute() : mixed
      {
             $this->rows = 0;
             $this->rowActual = 0;

             // Si existeix una transaccio iniciada, aleshores executam aquesta sentencia dins la TX
          $cnx = $this->hndTrans ?? $this->connDb;

          if($this->result !== null && gettype($this->result) === "resource")
            {
                  @ibase_free_result($this->result);
            }

            $args = $this->params;
             if ($this->blobID)
                 $args[] = $this->blobID;

             $this->result = @ibase_query($cnx, $this->query, ...$args);

             if ($this->result === false)
             {
                 $this->error = $this->dataBase->lastError();
                 if (str_contains($this->error, "too many open handles") || empty($this->error))
                 {
                     $this->dataBase->Close();
                     $this->dataBase->Open();
                 }

                 if (empty($this->error))
                     $this->error = "Unknown Firebird API client error";

                 throw new errorQuerySQL($this->error, $this->query);
             }

             $queryKey = sha1($this->query);
             $this->typesDefinition[$queryKey] = [];

             if (preg_match("/^[ \n\r\t]*(select|SELECT)/", $this->query))
             {
                $this->myEof = false;                 
                $this->rowActual = -1;  
                $this->Skip();
             }

             return $this->result; 
      }         

      final public function LastRecord() : int
      {
            $i=0;
            $res = @ibase_query($this->connDb, $this->query, ...$this->params);
            while(@ibase_fetch_row($res))
                  $i++;
            ibase_free_result($res);
            return $i;
      }
      
      public function NumFields() : ?int
      {
          if (!$this->result)
              return null;

          return ibase_num_fields($this->result);
      }

      private function GetFieldDef(string $attribute, int $num)
      {
          $queryKey = sha1($this->query);

          if (array_key_exists($queryKey, $this->typesDefinition) &&
              array_key_exists($num, $this->typesDefinition[$queryKey]))
          {
              return $this->typesDefinition[$queryKey][$num][$attribute];
          }

          $this->typesDefinition[$queryKey][$num] = @ibase_field_info($this->result, $num);
          return $this->typesDefinition[$queryKey][$num][$attribute];
      }

      public function GetFieldName($field)
      {
          if (!$this->result)
              return null;
          return $this->GetFieldDef('name', $field);
      }
      
      public function GetFieldType($field)
      {
	     $def =  @ibase_field_info($this->result, $field);
         return $def["type"];
      }

      public function GetFieldRelation($field)
      {
          if (!$this->result)
              return null;
          return $this->GetFieldDef('relation', $field);
      }
      
      public function GetFieldAlias($field)
      {
          if (!$this->result)
              return null;
          return $this->GetFieldDef('alias', $field);
      }

      public function GetFieldLength($field)
      {
          if (!$this->result)
              return null;
          return $this->GetFieldDef('length', $field);
      }

      protected function StoreBLOB($fileDescriptor) : false | string
      {
            return @ibase_blob_import($this->connDb, $fileDescriptor);
      }
      
      public function LoadFromBLOB($rowBlob) : string
      {
            $data = '';            
            $blobHandler = @ibase_blob_open($this->connDb, $rowBlob);

            if (!$blobHandler)
               return $data;

            while($blobData = @ibase_blob_get($blobHandler, 100)) 
                  $data.= $blobData;

            @ibase_blob_close($blobHandler);
            return $data;
      }

      public function Commit($idTrans=null) : bool
      {
			 // Si ens pasen un ID de transsaccio llavors commitam aquella transaccio
			 // si no, commitam totes les transaccions

             unset($this->hndTrans);
             if ($idTrans === null)
	             return @ibase_commit($this->connDb);  
			 return @ibase_commit($idTrans);
      }

      public function Rollback($idTrans=null)  : bool
      {
             unset($this->hndTrans);
             if ($idTrans === null)
	             return @ibase_rollback($this->connDb);
			 return @ibase_rollback($idTrans);
      }

    /**
     * @return resource
     * @throws errorTransSQL
     */
      public function BeginTrans()
      {
             unset($this->hndTrans);
             if (!($hndTrans = @ibase_trans(IBASE_CONCURRENCY, $this->connDb)))
				throw new errorTransSQL($this->dataBase->lastError());

             $this->hndTrans = $hndTrans;
             return $hndTrans;
      }

      public function NextID($generatorID)
      {
             return @ibase_gen_id($generatorID,1);
      }

      public function Close() : void
      {
            if($this->result !== null && gettype($this->result) === "resource")
            {
                @ibase_free_result($this->result);
            }
                
            $this->myEof = false;                                      
            $this->rows=-1;
            $this->rowActual=-1;
            $this->result=null;
      }
}
