<script type="text/javascript">

  <?php
  $labels = [];
  $in_traffic_sum = '';
  $in_traffic = '';
  $out_traffic = '';
  $images_charts = '';
  $date_format = 'm/d/Y';  //Date format in dashboard chart

  function format_KB($value)
  {
    $value = $value / 1000;
    $value = $value / 1000;

    return round($value, 2);
  }

  $labels_dates = [];
  $limit = 10;

  // Calculate offset
  $item = 0;

    if (empty($gui::$stats_live)) {
      // Sample data
      $statsclass = new wps_ic_stats();
      $stats = $statsclass->fetch_sample_stats();
    } else {
      $stats = $gui::$stats_live;
    }



  if ($stats) {
    foreach ($stats as $date => $value) {
      $index = date('d-m-Y', strtotime($date));
      $labels[$index]['date'] = date('m/d/Y', strtotime($date));
      $labels[$index]['total_input'] = $value->original;
      $labels[$index]['total_output'] = $value->compressed;

      if ($labels[$index]['total_input'] < 0) {
        $labels[$index]['total_input'] = 0;
      }

      if ($labels[$index]['total_output'] < 0) {
        $labels[$index]['total_output'] = 0;
      }
    }
  }

  asort($labels);

  $count_labels = count($labels);
  if ($count_labels == 4) {
    $catpercentage = 0.20;
  } elseif ($count_labels == 3) {
    $catpercentage = 0.12;
  } elseif ($count_labels <= 2) {
    $catpercentage = 0.05;
  } elseif ($count_labels >= 5 && $count_labels <= 8) {
    $catpercentage = 0.2;
  } elseif ($count_labels >= 8 && $count_labels <= 10) {
    $catpercentage = 0.4;
  } else {
    $catpercentage = 0.55;
  }

  // Parse to javascript
  $labels_js = '';
  $biggestY = 0;
  if ($labels) {
    foreach ($labels as $date => $data) {
      $labels_js .= '"' . date($date_format, strtotime($data['date'])) . '",';
      $in_traffic .= format_KB($data['total_input'] - $data['total_output']) . ',';
      $out_traffic .= format_KB($data['total_output']) . ',';
      $in_traffic_sum .= format_KB($data['total_input']) . ',';

      $kbIN = format_KB($data['total_input']);
      $kbOUT = format_KB($data['total_output']);

      if ($kbIN > $kbOUT) {
        if ($biggestY < $kbIN) {
          $biggestY = $kbIN;
        }
      } else {
        if ($biggestY < $kbOUT) {
          $biggestY = $kbOUT;
        }
      }
    }
  }

  // Calculate Max
  $biggestY = ceil($biggestY);
  $fig = (int)str_pad('1', 2, '0');
  $maxY = ceil((ceil($biggestY * $fig) / $fig));

  $stepSize = $maxY / 10;

  if ($maxY <= 10) {
    $stepSize = 1;
  } elseif ($maxY <= 100) {
    $stepSize = 10;
  } elseif ($maxY <= 500) {
    $stepSize = 25;
  } elseif ($maxY <= 1000) {
    $stepSize = 100;
  } elseif ($maxY <= 2000) {
    $stepSize = 200;
  } else {
    $stepSize = 500;
  }

  if (!empty($labels) && !empty($stats)) {


  $out_traffic = rtrim($out_traffic, ',');
  $in_traffic = rtrim($in_traffic, ',');
  $images_charts = rtrim($images_charts, ',');


  $labels_js = rtrim($labels_js, ',');

  ?>

  function tooltipValue(bytes) {
      var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
      if (bytes == 0) return '0 Byte';
      bytes = bytes * 1000 * 1000;
      var i = parseInt(Math.floor(Math.log(bytes) / Math.log(1000)));
      return (bytes / Math.pow(1000, i)).toFixed(1) + ' ' + sizes[i];
  }

  var var_barPercentage = 0.6;
  var var_barThickness = 10;
  var var_maxBarThickness = 12;
  var var_minBarLength = 2;
  var trafficSum = '<?php echo rtrim($in_traffic_sum, ','); ?>';

  const footer = (tooltipItems) => {
      let sum = 0;

      var tooltip = tooltipValue(tooltipItems.yLabel);

      // Index of the column
      var index = tooltipItems[1].dataIndex;
      var before = tooltipItems[0].dataset.data[index];
      var after = tooltipItems[1].dataset.data[index];

      tooltipItems[0].dataset.data[index] = tooltipItems[0].dataset.data[index] + tooltipItems[1].dataset.data[index];

      if (tooltipItems.datasetIndex == 0) {
          // Original
          var prefix = 'After Optimization ';
      } else {
          // Compressed
          var prefix = 'Original ';
      }

      if (tooltipItems.datasetIndex == 0) {
          //return prefix + tooltip;
      } else {
          //return prefix + tooltipValue(before + after);
      }

  };

  var sumLabel = 0;

  <?php
  if (empty(self::$settings['live-cdn']) || self::$settings['live-cdn'] == '0') {
  ?>
  var config = {
      type: 'bar',
      data: {
          labels: [<?php echo $labels_js; ?>],
          datasets: [{
              label: "After Optimization",
              fill: false,
              backgroundColor: '#3c4cdf',
              borderColor: '#3c4cdf',
              barPercentage: var_barPercentage,
              barThickness: var_barThickness,
              maxBarThickness: var_maxBarThickness,
              minBarLength: var_minBarLength,
              data: [
                <?php echo $out_traffic; ?>
              ],
              fill: false,
          }, {
              label: "Original",
              fill: false,
              backgroundColor: '#09a8fc',
              borderColor: '#09a8fc',
              borderRadius: 20,
              barPercentage: var_barPercentage,
              barThickness: var_barThickness,
              maxBarThickness: var_maxBarThickness,
              minBarLength: var_minBarLength,
              data: [
                <?php echo $in_traffic; ?>
              ],
              fill: false,
          }]
      },
      options: {
          animation: {
              duration: 0
          },
          responsive: true,
          maintainAspectRatio: false,
          title: {
              display: false,
              text: ''
          },
          interaction: {
              intersect: false,
              mode: 'index',
              itemSort: function (a, b) {
                  return b.datasetIndex - a.datasetIndex
              },
          },
          plugins: {
              legend: {
                  display: false,
              },
              tooltip: {
                  callbacks: {
                      footer: footer,
                      label: function (tooltipItems, context) {
                          if (tooltipItems.datasetIndex == '1') {
                              var trafficTotal = trafficSum.split(',');
                              return 'Original ' + trafficTotal[tooltipItems.dataIndex] + ' MB';
                          }

                          return tooltipItems.dataset.label + ' ' + tooltipItems.formattedValue + ' MB';
                      }
                  }
              },

          },
          elements: {
              line: {
                  tension: 0.00000001
              }
          },
          scales: {
              x: {
                  barThickness: 20,
                  stacked: true,
                  display: true,
                  scaleLabel: {
                      display: false,
                      labelString: 'Month'
                  }
              },
              y: {
                  stacked: true,
                  display: true,
                  scaleLabel: {
                      display: false,
                      labelString: 'MB'
                  }
              }
          }
      }

  };
  <?php } else { ?>

  var config = {
      type: 'bar',
      data: {
          labels: [<?php echo $labels_js; ?>],
          datasets: [{
              label: "After Optimization",
              fill: false,
              backgroundColor: '#3c4cdf',
              borderColor: '#3c4cdf',
              barPercentage: var_barPercentage,
              barThickness: var_barThickness,
              maxBarThickness: var_maxBarThickness,
              minBarLength: var_minBarLength,
              data: [
                <?php echo $out_traffic; ?>
              ],
              fill: false,
          }, {
              label: "Original",
              fill: false,
              backgroundColor: '#09a8fc',
              borderColor: '#09a8fc',
              borderRadius: 20,
              barPercentage: var_barPercentage,
              barThickness: var_barThickness,
              maxBarThickness: var_maxBarThickness,
              minBarLength: var_minBarLength,
              data: [
                <?php echo $in_traffic; ?>
              ],
              fill: false,
          }]
      },
      options: {
          animation: {
              duration: 0
          },
          responsive: true,
          maintainAspectRatio: false,
          title: {
              display: false,
              text: ''
          },
          interaction: {
              intersect: false,
              mode: 'index',
              itemSort: function (a, b) {
                  return b.datasetIndex - a.datasetIndex
              },
          },
          plugins: {
              legend: {
                  display: false,
              },
              tooltip: {
                  callbacks: {
                      footer: footer,
                      label: function (tooltipItems, data) {
                          if (tooltipItems.datasetIndex == '1') {
                              var trafficTotal = trafficSum.split(',');
                              return 'Original ' + trafficTotal[tooltipItems.dataIndex] + ' MB';
                          }
                          return tooltipItems.dataset.label + ' ' + tooltipItems.formattedValue + ' MB';
                      }
                  }
              },

          },
          elements: {
              line: {
                  tension: 0.00000001
              }
          },
          scales: {
              x: {
                  barThickness: 20,
                  stacked: true,
                  display: true,
                  scaleLabel: {
                      display: false,
                      labelString: 'Month'
                  }
              },
              y: {
                  stacked: true,
                  display: true,
                  scaleLabel: {
                      display: false,
                      labelString: 'MB'
                  }
              }
          }
      }

  };
  <?php } ?>

  <?php } ?>
</script>
<script type="text/javascript">
    jQuery(document).ready(function ($) {

      <?php if (!empty($labels) && !empty($stats)) { ?>
        setTimeout(function () {
            if ($('#wpc-canvas').length) {
                var ctx = document.getElementById("wpc-canvas").getContext("2d");
                window.myLine = new Chartwpc(ctx, config);
            }
        }, 200);
      <?php } ?>
    });
</script>