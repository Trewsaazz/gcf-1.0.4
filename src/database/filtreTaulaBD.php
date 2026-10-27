<?php

namespace gcf\database;

use gcf\database\models\ResultSet;
use gcf\database\models\DataMapper;

class filtreTaulaBD
{
      public $where, $fields, $numRows, $camps;

    /**
     * @var ?SQLQuery
     */
      private $query;

      private $taula;
      private $joins, $orderBy;
       
      static $instance = false;
     
      private $numJoin;
      
      public function __construct(DataMapper $taula)
      {
             $this->query = null;
             $this->taula = $taula;
             $this->joins = "";
             $this->fields = array();
             $this->numRows = $this->numJoin = 0;
      }

      public function __get($property)
      {
              $property = strtoupper($property);
              if (isset($this->camps[$property]))
                 return $this->camps[$property];
              else
                 return false;
      }

      public function __set($property, $value)
      {
             $property = strtoupper($property);
             $this->camps[$property] = $value;
      }

      public function addField($fieldName) : void
      {
             if(!in_array($fieldName, $this->fields)) 
                $this->fields[] = $fieldName;
      }
       
      public function leftJoin(DataMapper $taulaJoin, $campJoinSrc, $campJoinDst) : void
      {
             $this->joins .= "left join {$taulaJoin->nomTaula} on {$taulaJoin->nomTaula}.$campJoinSrc = $campJoinDst ";
      }

    /**
     * @throws invalidOrder
     */
    public function orderBy($campsOrdres, $tipus="asc")
      {
             if (!(($tipus == "asc") || ($tipus == "desc")))
                throw new invalidOrder("el tipus d'ordre es invalid");
 
             $this->orderBy = "order by ".$campsOrdres." ".$tipus;        
      }

      private function allibera()
      {
             if($this->query !== null)
             {
                $this->query->tanca_consulta();
                unset($this->query);
             }
      }

      private function DoQuery($id="")
      {
          $fieldsStr = "*";
          if ($this->where) $where = "where ".$this->where;
          if (count($this->fields)>0)
              $fieldsStr = implode(",",$this->fields);

          $where = "";
          $params = [];
          if ($id)
          {
              $where .=" ".$this->taula->condPrimaryKey($id);
              $params = $this->taula->getPkParams();
          }

          $qStr = "select $fieldsStr from {$this->taula->nomTaula} ".$this->joins." ".$where." ".$this->orderBy;
          $this->query = new SQLQuery($this->taula->getConnection());
          $this->query->fer_consulta($qStr, $assoc=true, $params);
      }

      final public function get($id="")
      {
            if ($this->query === null)
                $this->DoQuery($id);

            $final = $this->query->Eof();

            $this->numRows = $this->query->LastRecord();
            $this->camps = array();

            if (!$final)
            { 
                $this->camps =  array_map(function ($value) { return utf8_encode($value); }, $this->query->row); 
                $this->query->Skip();
                if ($id) $this->allibera();
            } else
                $this->allibera();
            
            return(!$final); 
      }

      final public function ResultSet() : ?ResultSet
      {
            $this->DoQuery();
            if (!empty($this->query))
                return new ResultSet($this->query, $this->taula->PrimaryKey());
            return null;
      }

      public function clean()
      {
             $this->allibera();
             $this->fields = array();
             $this->where = "";
             $this->orderBy = "";
             $this->numRows = 0;
      }

      public function getInstance($parent)
      {
             if(!filtreTaulaBD::$instance)
                 filtreTaulaBD::$instance = new filtreTaulaBD($parent);

             return filtreTaulaBD::$instance;
      }
}
