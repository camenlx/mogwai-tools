<html lang="en">
<head>
  <title>MN Mogwai Masternodes</title>
  <link href="//stackpath.bootstrapcdn.com/bootstrap/4.1.0/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9gVQ4dYFwwWSjIDZnLEWnxCjeSWFphJiwGPXr1jddIhOegiu1FwO5qRGvFXOdJZ4" crossorigin="anonymous">
  <link href="//cdn.datatables.net/1.10.16/css/jquery.dataTables.min.css" rel="stylesheet" crossorigin="anonymous">

  <script src="//code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
  <script src="//cdn.datatables.net/1.10.16/js/jquery.dataTables.min.js" crossorigin="anonymous"></script>
  <script src="https://momentjs.com/downloads/moment.min.js"></script>
  <script src="mnnames.js"></script>

  <style>
    th { font-size: 11px; }
    td { font-size: 10px; }
    .codestring {
      color: #a11;
      font-family: Monaco,Menlo,Consolas,"Courier New",monospace!important;
    }
    .c000_PAYING td {
        color: purple;
    }
    .c001_10_PERCENT td {
        color: darkgreen;
    }
    .c002_90_PERCENT td {
        color: goldenrod;
    }
    .c003_NEW td {
        color: darkblue;
    }
    .c004_INVALID td {
        color: red;
    }
    .truncate {
      width: 50px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    span.bord {
        border: 2px solid black;
    }
  </style>
</head>

<body>
  <div>You can view this data from your own node by running: <span class="codestring">`mogwai-cli masternodelist full`</span>  (and optionally add a filter at the end to see just certain rows)</div>
  <div>
    Block: <span id="nextblock" class="bord"></span> 
    Difficulty: <span id="nextdifficulty" class="bord"></span>  
    Eligible: <span id="eligible" class="bord"></span>
    Real %: <span id="real_pct" class="bord"></span>
  </div>
  <input type="text" class="global_filter" id="global_filter">
  <br>
  <table id="dtable" class="table display" cellspacing="0" width="100%"></table>


  <script>
    const block_explorer = 'http://explorer.mogwaicoin.org';

    // link to block explorer for an address
    function r_addr_link(data, type, row) {
      var name = ''; 
      if (mnnames && mnnames[data]) {
          name = mnnames[data];
      }

      if ( type !== "display" ) {
        return name + data;
      }

      if (!data) {
        return '';
      }

      // if we have a known name, use it 
      if (!name) name = data;
      return "<a href='" + block_explorer + "/address/" + data + "' target='_blockexplorer'>" + name + "</a>";
    }

    // link to block explorer for a block
    function r_block_link(data, type, row) {
      if ( type !== "display" ) {
        return data;
      }

      if (parseInt(data, 10) <= 0) {
        return '';
      }
      return "<a href='" + block_explorer + "/block/" + data + "' target='_blockexplorer'>" + data + "</a>";
    }

    // reduce the key length column, but provide mouseover
    function r_key(data, type, row) {
      if ( type !== "display" ) {
        return data;
      }

      return "<span class='truncate' title='"+data+"'>" + data.substr(0,35) + " ...</span>";
    }

    // duration in seconds to a nicely formatted string
    function r_duration_fmt(data, type, row) {
      if ( type !== "display" ) {
        return data;
      }

      if (parseInt(data, 10) <= 0) {
        return '';
      }


      var days = Math.floor(data / (60*60*24));
      days = (days > 0) ? (days + " days ") : '';
      var rem = moment.unix(data % (60*60*24)).utc().format("HH:mm:ss");

      return days + rem;

    }

    // time in seconds to a nicely formatted string
    function r_time_fmt(data, type, row) {
      if ( type !== "display" ) {
        return data;
      }

      if (parseInt(data, 10) <= 0) {
        return '';
      }
      return r_duration_fmt(Math.abs(moment.unix(data).diff() / 1000), type, row);
      // return moment.unix(data).diff();
      // return moment.unix(data).fromNow();
      // return moment.unix(data).format("YYYY-MM-DD HH:mm:ss");
    }

    // time in seconds to a nicely formatted string
    function r_time_estimate(data, type, row) {
      var now = (moment() + 0) / 1000; 

      if (row.lastpaidtime) { 
          data = row.estimate - row.lastpaidtime;
      }
      else {
          data = data + row.activeseconds;
      }

      if ( type !== "display" ) {
        if (data < 25*60) {
            return "99999999999";
          }
        return data;
      }

      if (!row.lastpaidtime) {
          return r_time_fmt(data, type, row);
      }

      if (data > row.activeseconds) {
          data = row.activeseconds + (row.estimate - now);
      }

      if (data < 25*60) {
        return "~";
      }


      var days = Math.floor(data / (60*60*24));
      days = (days > 0) ? (days + " days ") : '';
      var rem = moment.unix(data % (60*60*24)).utc().format("HH:mm:ss");

      return days + rem;

      // return r_duration_fmt(Math.abs(moment.unix(data).diff() / 1000), type, row);
    }

    // colorize the statuses
    function r_status_color(data, type, row) {
      if ( type !== "display" ) {
        return data;
      }

      switch (data) {
        case "ENABLED":
          return "<span style='color:green'>ENABLED</span>";
          break;
        case "ENABLED_WD_EXP":
          return "<span style='color:darkgreen'>ENABLED (WD EXP)</span>";
          break;
        default:
          return "<span style='color:red'>" + data + "</span>";
          break;
      }
    }

    function filterGlobal () {
        $('#dtable').DataTable().search(
            $('#global_filter').val(),
            regex = true,
            smart = false
        ).draw();
    }

    //put results into a datatable
    $(document).ready(function() {
      var table = $('#dtable')
      .on( 'init.dt', function () {
            //console.log( 'Table initialisation complete: '+new Date().getTime() );
        } )
      .on('xhr.dt', function ( e, settings, json, xhr ) {
            window.dt_data = json;
            //console.log( 'XHR: '+new Date().getTime(), json );
            if (json) {

                $('#nextblock').html(json.getblocktemplate.height);
                $('#nextdifficulty').html(json.getblocktemplate.difficulty);
                $('#eligible').html(json.mn_stats.qualify);
                $('#real_pct').html(Math.round(json.data.length / json.mn_stats.qualify * 1000)/100 + " %");

                // get counts of versions
                versions = {};
                for (mn of json.data) {
                    if (mn.status.substr(0, 7) == "ENABLED") {
                        versions[mn.protocol] = versions[mn.protocol] ? versions[mn.protocol] + 1 : 1;
                    }
                }

                // for (ver in versions) {
                //     console.log('version', ver, versions[ver]);
                // }

                // total = versions['70208'] + versions['70209'];
                // upgraded = 100 * versions['70209'] / total;
                // console.log('upgraded %', upgraded)
            }
      })
      .DataTable({
        "dom": '<"top"i>rt<"bottom"><"clear">',
        "paging": false,
        "order": [[ 0, 'asc' ]],
        "ajax": "mnlist.php",
        "columns": [
            { title: "pos", data: "pos" },
            { title: "tier", data: "tier" },
            { title: "key", data: "key", render: r_key },
            { title: "payee", data: "payee", render: r_addr_link },
            { title: "IP", data: "IP" },
            { title: "protocol", data: "protocol" },
            { title: "status", data: "status", render: r_status_color },
            { title: "lastseen", data: "lastseen", render: r_time_fmt },
            { title: "activeseconds", data: "activeseconds", render: r_duration_fmt },
            { title: "lastpaidtime", data: "lastpaidtime", render: r_time_fmt },
            { title: "estimate", data: "estimate", render: r_time_fmt },
            { title: "est_total", data: "estimate", render: r_time_estimate },
            { title: "lastpaidblock", data: "lastpaidblock", render: r_block_link },
        ],
        "createdRow": function(row, data, dataIndex) {
            $(row).addClass("c" + data["tier"]);
        }
      });

      setInterval(function () {
        table.ajax.reload();
      }, 30000 );

      $('input.global_filter').on( 'keyup click', function () {
        filterGlobal();
        localStorage.setItem('global_filter', $('#global_filter').val());
      });


      var filter;
        if (filter = localStorage.getItem('global_filter')) {
            $('#global_filter').val(filter);
            filterGlobal();
        }
    });

  </script>
</body>
</html>
