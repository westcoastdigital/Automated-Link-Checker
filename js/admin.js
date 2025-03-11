jQuery(document).ready(function($) {
    // Update the broken link count display
    function updateBrokenLinkCount() {
        var count = $('table tbody tr').length;
        $('#broken_link_count').text(count);
    }

    // Initialize count
    updateBrokenLinkCount();

    // Update count after deletion
    $(".delete_link").on("click", function(e) {
        e.preventDefault();

        var row = $(this).closest('tr');

        // Assuming you're using AJAX to handle the deletion
        $.ajax({
            url: 'your_delete_url', // Replace with your actual URL
            type: 'POST',
            data: { id: row.data('id') }, // Assuming you have a data-id attribute on the row
            success: function(response) {
                if (response.success) {
                    row.fadeOut(300, function() {
                        $(this).remove();
                        updateBrokenLinkCount(); // Update count after removal
                    });
                } else {
                    // Your existing error handling here
                    console.log('Error: ' + response.message);
                }
            },
            error: function() {
                // Handle AJAX error here
                console.log('AJAX request failed');
            }
        });
    });

    // CSV Download functionality
    $("#download_broken_links").on("click", function(e) {
        e.preventDefault();

        // Get table data
        var csvData = [];
        var headers = [];

        // Get headers
        $('table thead th').each(function() {
            headers.push($(this).text().trim());
        });
        csvData.push(headers);

        // Get table data
        $('table tbody tr').each(function() {
            var rowData = [];
            $(this).find('td').each(function(index) {
                // For URL columns, get the text without the trash icon
                if (index === 1) {
                    rowData.push($(this).text().trim().replace(/\s+$/, ''));
                } else if (index === 2) {
                    // For Post URL, get just the URL text
                    rowData.push($(this).find('a:first').text().trim());
                } else {
                    rowData.push($(this).text().trim());
                }
            });
            csvData.push(rowData);
        });

        // Convert to CSV string
        var csvString = '';
        csvData.forEach(function(row) {
            csvString += row.map(function(cell) {
                // Quote cells that contain commas or quotes
                if (cell.indexOf(',') !== -1 || cell.indexOf('"') !== -1) {
                    return '"' + cell.replace(/"/g, '""') + '"';
                }
                return cell;
            }).join(',') + '\n';
        });

        // Create download
        var downloadLink = document.createElement('a');
        downloadLink.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvString);
        downloadLink.download = 'broken_links_' + new Date().toISOString().slice(0, 10) + '.csv';

        // Append, click, and remove
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    });
});
