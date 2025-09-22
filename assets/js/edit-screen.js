document.addEventListener('DOMContentLoaded', () => {

    const rows = document.querySelectorAll('#the-list tr.status-archive');

    rows.forEach((row) => {
        disallowEditing(row);
    });

    function disallowEditing(row) {
        const title = row.querySelector('.column-title a.row-title').textContent;

        row.querySelector('.column-title a.row-title').outerHTML = title;
    }
});
