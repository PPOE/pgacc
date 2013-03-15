<?php
/**
 * @package api-framework
 * @author Peter Grassberger <petertheone@gmail.com>
 * @abstract
 */

require("../functions.php");

class DonationsController{

    function date_condition($year,$begin = 1) {
        if ($begin == 0)
            return "AND date < '".(intval($year)+1)."-01-01'";
        else
            return "AND date >= '".$year."-01-01' AND date < '".(intval($year)+1)."-01-01'";
    }

    /**
     * @return mysqli
     */
    private function db_connect() {
        $db_con = pg_connect("dbname=accounting")
            or die('Verbindungsaufbau fehlgeschlagen: ' . pg_last_error());

        return $db_con;
    }

    /**
     * GET method.
     *
     * @param Request $request
     * @return string
     */
    public function get($request) {
        $db_con = $this->db_connect();

        $year = 2012;
        if (isset($request->parameters['year'])) {
            $year = intval($request->parameters['year']);
        }

        $donation_condition = "AND type = 8";
        $query = "SELECT name,SUM(amount) AS sum FROM vouchers WHERE ".eyes()." AND NOT member $donation_condition ".$this->date_condition($year)." GROUP BY name,street,plz,city;";
        $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
        while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            if ($year != 2012 && $line['sum'] <= 10000) { continue; }
            if ($year == 2012 && $line['sum'] <= 0) { continue; }
            $line['member_id'] = 0;
            $donations[] = $line;
        }
        pg_free_result($result);

        $query = "SELECT member_id,name,SUM(amount) AS sum FROM vouchers WHERE ".eyes()." AND member $donation_condition ".$this->date_condition($year)." GROUP BY member_id,name;";
        $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
        $count = 0;
        $donations = array();
        while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            if ($year != 2012 && $line['sum'] <= 10000) { continue; }
            if ($year == 2012 && $line['sum'] <= 0) { continue; }
            $donations[] = $line;
            $count++;
        }
        pg_free_result($result);

        $result = array();
        if ($count > 0) {
            foreach ($donations as $d)
            {
                $sort_sum[] = $d['sum'];
                $sort_name[] = $d['name'];
            }

            array_multisort($sort_sum, SORT_DESC, $sort_name, SORT_ASC, $donations);


            foreach ($donations as $line)
            {
                $name = $line["name"];
                $result[$name] = $line["sum"] / 100.0;
            }
        }

        return $result;
    }
}