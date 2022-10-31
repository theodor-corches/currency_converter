<?php

namespace tore;

use DateTime;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SimpleXMLElement;
use SQLite3;

/**
 * Serves as the application container.
 *
 * The application container has a local SQLite3 database.
 */
class App {

    /**
     * @var array the configuration array
     */
    private $_config;

    /**
     * @var SQLite3 the database handle
     */
    private $_db;

    /**
     * @param array $config configuration array
     * @return void
     */
    public function __construct(array $config) {
        $this->_config = (object)$config;
    }

    /**
     * Initializes the object.
     * @return void
     */
    public function init() {
        $this->_db = new SQLite3($this->_config->dbpath);
    }

    /**
     * Fetches the contents of an URL using CURL.
     * @param string $url the url to be fetched
     * @return string the content of the url
     */
    public static function get_url_data(string $url): string {
      //  $xml=simplexml_load_file($url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_FAILONERROR,1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $retValue = curl_exec($ch);
        curl_close($ch);
        return $retValue;
    }


    /**
     * Fetches the URLs in a loop and creates an array.
     * The fetched URLs using get_url_data_method contain XML-Data which must be converted
     * to an array using the convert_to_array method.
     * @param array $urls the list of urls to be fetched
     * @return array the combined ECB rate data
     */
    public static function gather_urls_data(array $urls): array {
        $resulted_data = [];
        foreach ($urls as $url => $value) {
            $result = self::get_url_data($value);
            $oXML = new SimpleXMLElement($result);
            array_push($resulted_data, $oXML);
        }
        return $resulted_data;
    }

    /**
     * This method fetches the rates data from the ECB website and returns it as array.
     * @return array Rates data from the ECB website
     */
    public static function get_web_data(): array {
        $urls = [
            'USD' => "https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/usd.xml",
            'CAD' => "https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/cad.xml",
        ];
        return self::gather_urls_data($urls);
    }

    /**
     * This method returns the exchange_rate table data.
     *
     * A header row containing ["Date", "USD", "CAD"] should be prepended to the data. The rows
     * must be in ascending order.
     * @return array Rates data from the exchange_rate table
     */
    public function get_rates_data(): ?array {


        $stmt = $this->_db->query('SELECT * FROM exchange_rate ORDER BY timestamp ASC');
        $values = [];
        $header = ["Date", "USD", "CAD"];
        $values[] = $header;
        while ($row = $stmt->fetchArray()) {
            $values[] = [
                 $row['timestamp'],
                 $row['usd'],
                 $row['cad']
            ];
        }

        return $values;
    }

    /**
     * This method checks if the timestamp exists in the exchange_rate table.
     * @param int $timestamp The value to look up
     * @return bool Returns true if the timestamp exists.
     */
    public function rate_timestamp_exists(int $timestamp): bool {
        $IDq = $this->_db->exec("SELECT * FROM exchange_rate WHERE timestamp= '$timestamp'");
        if($IDq['timestamp'])
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * This method merges the rates data into the database.
     *
     * The header of the array to be imported should be skipped. The import array
     * has the 3 columns [timestamp, usd, cad]. Each row is only inserted if the timestamp of the row
     * does not exist in the local table using **rate_timestamp_exists** method. Transactions can be used
     * to speed up the SQL-operations.
     * @param array $data Rates data to be merged in the database.
     * @return void
     */
    public function merge_rates_data(array $data) {
        $courses = array();
        $i = 0;
        $j = 0;
        foreach ($data[0]->DataSet->Series->children() as $item){
            foreach ($data[0]->DataSet->Series->Obs[$i]->attributes() as $name=>$elem) {
                if($name == 'TIME_PERIOD'){
                    $dtime = DateTime::createFromFormat("Y-m-d", $elem[0]);
                    $timestamp = $dtime->getTimestamp();
                    $courses[$i]['timestamp'] = $timestamp * 1000;
                }
                if($name == 'OBS_VALUE') {
                    $courses[$i]['usd'] = (float)$elem[0];
                }
            }
            $i++;
        }
        foreach ($data[1]->DataSet->Series->children() as $item){
            foreach ($data[1]->DataSet->Series->Obs[$j]->attributes() as $name=>$elem) {
                if($name == 'OBS_VALUE') {
                    $courses[$j]['cad'] = (float)$elem[0];
                }
            }
            $j++;
        }

        foreach ($courses as $course){
            if(self::rate_timestamp_exists($course['timestamp']) === false){
                $timestamp = $course['timestamp'];
                $usd = $course['usd'];
                $cad = $course['cad'];
                $this->_db->exec("INSERT INTO exchange_rate(timestamp, usd, cad) VALUES('$timestamp', '$usd', '$cad')");
            }
        }
    }

    /**
     * This method returns the maximum timestmap in the exchange_rate table.
     * @return int Returns the maximum timestamp value
     */
    public function max_rates_timestamp(): ?int {
        $sql = 'SELECT MAX(timestamp) FROM exchange_rate LIMIT 1;';
        $result = $this->_db->query($sql)->fetchArray();

        return $result[0];
    }

    /**
     * This method deletes all the rows in the exchange_rate table.
     * @return void
     */
    public function reset_rates_data() {
        $this->_db->exec("DELETE FROM exchange_rate");
    }

    /**
     * This method places the rates data into an excel sheet.
     *
     * The timestamp information must be converted into an excel format. The column dimensions
     * can be optionally set to autowidth.
     * @return void
     */
    public static function set_excel_data(array $data, &$sheet) {
        // $header is an array containing column headers

        foreach($data as $cell => $key){
            if($cell != 0){
                $dateTime = time();
                $key[0] = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(
                    $dateTime );
            }
        }
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data, NULL, 'A1');

        // redirect output to client browser
        header('Content-Disposition: attachment;filename="myfile.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }

}
