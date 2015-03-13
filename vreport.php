<?php
function v_graph($konto, $date_begin, $date_end)
{
  if ($konto === "") {
    return;
  }

  echo '<script src="js/jquery-1.11.0.min.js"></script>' . "\n";
  echo '<script src="js/jquery.flot.js"></script>' . "\n";
  echo '<script src="js/jquery.flot.time.js"></script>' . "\n";

  $cond = " AND vaccount LIKE '$konto%' ";
  if (strpos($konto, ",") !== false) {
    $konto = "'" . implode("','", explode(",", $konto)) . "'";
    $cond = " AND vaccount IN ($konto) ";
  }
  if ($date_begin)
    $cond .= " AND date >= '$date_begin' ";
  if ($date_end)
    $cond .= " AND date <= '$date_end' ";

  $query = "SELECT extract(epoch FROM date) * 1000 AS timestamp, SUM(amount) / 100.0, (SELECT SUM(amount) / 100.0 FROM vouchers WHERE date <= v.date AND NOT deleted $cond ) AS runningtotal FROM vouchers v WHERE NOT deleted $cond GROUP BY date ORDER BY date;";
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  //$array = pg_fetch_all($result);
  $array = array();
  $min = 0;
  while ($line = pg_fetch_array($result)) {
    $array[] = array($line['timestamp'], $line['runningtotal']);
    $min = $line['runningtotal'] < $min ? $line['runningtotal'] : $min;
  }
  echo '<script>var values = ' . json_encode($array) . ';</script>' . "\n";
  echo '<div id="graph" style="width: 100%; height: 400px;"></div>';
  ?>
    <script>
      var previousPoint = null;
      $("#graph").bind("plothover", function(event, pos, item) {
        if (item) {
	  if (previousPoint == item.dataIndex) {
            return;
          }
          previousPoint = item.dataIndex;
          $('#tooltip').remove();
          var date = new Date(item.datapoint[0]);
          var dateformatted = date.getDate() + '.' + (date.getMonth() + 1) + '.' + date.getFullYear();
	  var value = item.datapoint[1] + '€';
          $('<div id="tooltip">' + dateformatted + ': ' + value + '</div>').css({
            position: 'absolute',
            top: item.pageY + 20,
            left: item.pageX - 60,
            display: 'none',
            padding: 8,
            'background-color': 'rgba(255, 255, 255, 0.8)'
          }).appendTo("body").fadeIn(400);
        } else {
          $('#tooltip').remove();
          previousPoint = null;
        }
      });

      $.plot(
        "#graph",
        [values], {
          series: {
            lines: {
              show: true,
              steps: true
            },
            points: {
              show: true
            }
          },
          grid: {hoverable: true, clickable: true},
          xaxis: {
            mode: "time", 
            timeformat: "%d.%m.%Y"
          },
          yaxis: {
            min: <?php echo $min * 1.1; ?>
          }
        }
      );
    </script>
  <?php
}

function v_report($konto,$date_begin,$date_end)
{
  global $realtype_req;
  $cond = " AND vaccount LIKE '$konto%' ";
  if (strpos($konto,",") !== false)
  {
    $konto = "'" . implode("','",explode(",",$konto)) . "'";
    $cond = " AND vaccount IN ($konto)";
  }
  if ($date_begin)
    $cond .= " AND date >= '$date_begin' ";
  if ($date_end)
    $cond .= " AND date <= '$date_end' ";
echo '
<table><tr><td>
<h3>Einnahmen</h3>
<table>
';
$query = "SELECT id,name FROM type WHERE income = 1 $realtype_req ORDER BY realtype ASC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>";
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
}
pg_free_result($result2);
echo '
</table>
</td>
<td>
<h3>Ausgaben</h3>
<table>
';
$query = "SELECT id,name FROM type WHERE income <= 0 $realtype_req ORDER BY realtype ASC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>";
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
}
pg_free_result($result2);
echo '
</table>
</td>
</tr>
</table>
';
}

function page_vreport($rights)
{
  //if (strlen($rights) > 0)
  //{
    $query2 = "SELECT vaccount FROM vouchers WHERE NOT deleted AND amount != 0 GROUP BY vaccount ORDER BY vaccount";
    $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
    echo "<ul>";
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
      $k = $line2['vaccount'];
      if ($rights == '' && is_numeric(str_replace(array(" ","AT"),array("",""),$k)))
      {
        $k = substr($line2['vaccount'],-3,3);
      }
      echo "<li><a href=\"vreport?konto=$k\">Konto '".$k."'</a></li>";
    }
    echo "</ul>";
    pg_free_result($result2);
  //}
  $konto = "";
  $date_begin = "";
  $date_end = "";
  if (isset($_GET["konto"]) && preg_match('/^[ A-Z0-9-,]+$/i', $_GET["konto"]) == 1)
    $konto = $_GET["konto"];
  if (isset($_GET["date_begin"]) && preg_match('/^[0-9]+-[0-9]+-[0-9]+$/i', $_GET["date_begin"]) == 1)
    $date_begin = $_GET["date_begin"];
  if (isset($_GET["date_end"]) && preg_match('/^[0-9]+-[0-9]+-[0-9]+$/i', $_GET["date_end"]) == 1)
    $date_end = $_GET["date_end"];
  echo "<h1>Bericht Konto $konto</h1><br>";
  block_start();
  v_graph($konto, $date_begin, $date_end);
  v_report($konto,$date_begin,$date_end);
  block_end();

}
?>
