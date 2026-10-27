<?php
/**
 * Created by PhpStorm.
 * User: tomeu
 * Date: 6/19/2018
 * Time: 9:06 PM
 */

namespace gcf\database\models;

use DateTime;

class DataConverter
{
    // TODO: A futur aquesta constant ha de desapareixer per agafar els parametres de configuració de BD
    const DEFAULT_DB_DATE_FMT = 'd.m.Y';

    private string $tableName;
    private bool $swapDate;
    private ?string $generalWhere;
    private array $whereParams = [];
    private DataMapper $table;
    public array $dataFields;

    /**
     * Valors dels placeholders "?" de la darrera sentencia generada, en ordre
     */
    public array $params = [];

    /**
     * Camps de la darrera sentencia que no son columnes de la taula i s'han descartat
     */
    public array $discardedFields = [];

    public function __construct(DataMapper $table, bool $swapDate = true)
    {
            $this->table = $table;
            $this->tableName = $table->nomTaula;
            $this->generalWhere = null;
            $this->dataFields = [];

            if (!$swapDate)
                $this->swapDate = ($table->getConnection()->drv === "firebird");
            else $this->swapDate = $swapDate;
    }

    /**
     * Read XML data and create dictionary field => value
     * @param $dades_xml
     * @return array
     */
    public static function ReadDataXML($dades_xml) : array
    {
	    //$recodifica = (mb_detect_encoding($dades_xml) === 'UTF-8');
        $dades_xml = str_replace('\\"','"', $dades_xml);
        $nodeArray = [];

        $xmlDom = new \DOMDocument("1.0", "utf-8");
        $xmlDom->loadXML($dades_xml);

        $root = $xmlDom->documentElement;
        foreach ($root->childNodes as $node)
        {
            // TODO: Improve this!
            if ($node->nodeName==='#text')
                continue;

	        $nodeArray[strtoupper($node->nodeName)] = $node->nodeValue;

            //$nodeArray[strtoupper($node->nodeName)] = ($recodifica) ?
            //    iconv("UTF-8", "ISO-8859-15", $node->nodeValue) : $node->nodeValue;
        }

        return $nodeArray;
    }

    /**
     * Adding additional where condition on final generated sentence
     * @param string|null $cond
     * @param array $params Valors dels placeholders "?" de la condició
     */
    public function Where(?string $cond, array $params = []) : void
    {
            $this->generalWhere = $cond;
            $this->whereParams = $params;
    }

    public function getParams() : array
    {
        return $this->params;
    }

    /**
     * Convert XML data to SQL sentence
     * @param ConverterType $type
     * @param $dataXML
     * @return string
     * @throws \Exception
     */
    public function XMLToSQL(ConverterType $type, $dataXML) : string
    {
        $this->dataFields = self::ReadDataXML($dataXML);
        return $this->ArrayToSQL($type, $this->dataFields);
    }

    /**
     * A partir dels camps tornats de la funcio tracta_xml generam la sentencia
     * SQL de la BD.
     *
     * @param ConverterType $tipus Tipus d'operació a generar
     * @param array $camps Llista de camps i els seus valors
     * @return string Sentencia SQL generada amb placeholders "?", els valors es recullen a getParams()
     * @throws noDataFound Si cap camp és una columna de la taula
     * @throws \Exception
     */
    public function ArrayToSQL(ConverterType $tipus, array $camps): string
    {
        $this->params = [];
        $this->discardedFields = [];

        $valorsCamps = [];
        $s_camps_db = [];
        $assignacions = [];
        $campBlob = null;
        $columnes = $this->table->getColumns();

        foreach($camps as $nom_camp => $valor)
        {
            if (empty($nom_camp))
                continue;

            // Els noms de camp no es poden parametritzar, només acceptam columnes reals de la taula
            if (!isset($columnes[strtoupper($nom_camp)]))
            {
                $this->discardedFields[] = $nom_camp;
                continue;
            }
            $nom_camp = $columnes[strtoupper($nom_camp)];

            if (trim((string)$valor)!=='')          // Use this comparsion instead empty because 0 values may confuse empty function
            {
                if($this->swapDate)
                {
                    if(preg_match("/^([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})$/", $valor,$data))
                    {
                        $campData = DateTime::createFromFormat("d/m/Y", $data[1]);
                        if ($campData === false)
                            throw new \Exception("$data[1] Data incorrecte");

                        $valor = $campData->format(self::DEFAULT_DB_DATE_FMT);
                    } else {
                        if (preg_match("/^([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4}).([0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2})$/", $valor, $dataHora))
                        {
                            $campData = DateTime::createFromFormat("d/m/Y", $dataHora[1]);
                            if ($campData === false)
                                throw new \Exception("$dataHora[1] Data incorrecte");

                            $valorDataFmt = $campData->format(self::DEFAULT_DB_DATE_FMT);
			                $valor = "$valorDataFmt $dataHora[2]";
                        } else {
                            if($valor==='?')                // Si un camp te per valor un? és que li passam un blob
                            {
                                if ($campBlob !== null)
                                    throw new \Exception("Només es pot guardar un BLOB per sentència");

                                // El driver afegeix el blobID al final dels paràmetres, el seu "?" ha d'anar el darrer
                                $campBlob = $nom_camp;
                                continue;
                            }
                        }
                    }
                }

                $this->params[] = $valor;
                $assignacions[] = [$nom_camp, "?"];
            }
            else
                $assignacions[] = [$nom_camp, "null"];
        }

        if ($campBlob !== null)
        {
            if ($tipus === ConverterType::SQLUpdate && !empty($this->whereParams))
                throw new \Exception("La condició d'un UPDATE amb BLOB no pot tenir paràmetres");

            $assignacions[] = [$campBlob, "?"];
        }

        if (empty($assignacions))
            throw new noDataFound("No hi ha cap camp vàlid per la taula $this->tableName");

        foreach ($assignacions as [$nom_camp, $valor])
        {
            $s_camps_db[] = $nom_camp;

            if($tipus == ConverterType::SQLInsert) {
                $valorsCamps[] = $valor;
            } else if($tipus == ConverterType::SQLUpdate) {
                $valorsCamps[] = "$nom_camp = ".$valor;
            }
        }

        if ($tipus === ConverterType::SQLUpdate)
            $this->params = [...$this->params, ...$this->whereParams];

        $query = '';
        $s_camps_valors = implode(",",$valorsCamps);

        if ($tipus === ConverterType::SQLInsert)
        {
            $query = "insert into $this->tableName (".implode(",", $s_camps_db).") values ($s_camps_valors);";
        } else
            if ($tipus === ConverterType::SQLUpdate)
            {
                $whereExp = "";
                if(!empty($this->generalWhere))
                   $whereExp = "where $this->generalWhere";
                $query = "update $this->tableName set $s_camps_valors $whereExp;";
            }

        return $query;
    }
}

