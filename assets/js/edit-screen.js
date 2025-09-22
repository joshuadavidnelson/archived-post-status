document.addEventListener('DOMContentLoaded', function() {

    var rows = document.querySelectorAll('#the-list tr.status-archive');

    rows.forEach(function(row) {
        disallowEditing(row);
    });

    function disallowEditing(row) {
        var title = row.querySelector('.column-title a.row-title').textContent;

        row.querySelector('.column-title a.row-title').outerHTML = title;
    }
});
