<!DOCTYPE html>
<html>
    <head>

    </head>
    <body style="border:0; margin: 0;" onload="subst()">
    <div  style="font-weight: 700;text-align: center;">
        This list must be read in conjunction with the tag sheet supplied. If there is no tag sheet please phone
        Carters immediately. Do not proceed without the tag sheet.
    </div>
    <table style=" width: 100%">
        <tr>
            <td class="section"></td>
            <td style="text-align:right;font-size: 14px;line-height: 1rem;  ">
                Page <span class="page"></span>
            </td>
        </tr>
    </table>
    <script>
        function subst() {
            var vars = {};
            var query_strings_from_url = document.location.search.substring(1).split('&');
            for (var query_string in query_strings_from_url) {
                if (query_strings_from_url.hasOwnProperty(query_string)) {
                    var temp_var = query_strings_from_url[query_string].split('=', 2);
                    vars[temp_var[0]] = decodeURI(temp_var[1]);
                }
            }
            var css_selector_classes = ['page', 'frompage', 'topage', 'webpage', 'section', 'subsection', 'date', 'isodate', 'time', 'title', 'doctitle', 'sitepage', 'sitepages'];
            for (var css_class in css_selector_classes) {
                if (css_selector_classes.hasOwnProperty(css_class)) {
                    var element = document.getElementsByClassName(css_selector_classes[css_class]);
                    for (var j = 0; j < element.length; ++j) {
                        element[j].textContent = vars[css_selector_classes[css_class]];
                    }
                }
            }
        }
    </script>
    </body>
</html>

