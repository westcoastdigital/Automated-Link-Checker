jQuery(document).ready(function($) {
    // Update the broken link count display
    function updateBrokenLinkCount() {
        var count = $('table tbody tr').length;
        $('#broken_link_count').text(count);
    }

    // Initialize count
    updateBrokenLinkCount();

    // Handle the deletion of broken links
    $(".delete_link").on("click", function(e) {
        e.preventDefault();
        
        var linkElement = $(this);
        var row = linkElement.closest("tr");
        var url = linkElement.data("link");
        var postId = linkElement.data("postid");
        
        if (confirm("Are you sure you want to delete this broken link from the content?")) {
            // Show loading state
            linkElement.html('<span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear;"></span>');
            
            $.ajax({
                url: alcAjax.ajaxurl,
                type: "POST",
                data: {
                    action: "alc_delete_link",
                    nonce: alcAjax.nonce,
                    url: url,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove the row from the table
                        row.fadeOut(300, function() {
                            $(this).remove();
                            updateBrokenLinkCount(); // Update count after removal
                        });
                    } else {
                        alert("Error: " + response.data.message);
                        linkElement.html('<span style="color:#b20022;" class="dashicons dashicons-trash"></span>');
                    }
                },
                error: function() {
                    alert("An error occurred. Please try again.");
                    linkElement.html('<span style="color:#b20022;" class="dashicons dashicons-trash"></span>');
                }
            });
        }
    });
    
    // Add some basic styling for the loading animation
    $("<style>")
        .prop("type", "text/css")
        .html(`
            @keyframes rotation {
                from {
                    transform: rotate(0deg);
                }
                to {
                    transform: rotate(359deg);
                }
            }
        `)
        .appendTo("head");

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
        downloadLink.download = 'broken_links_' + new Date().toISOString().slice(0,10) + '.csv';
        
        // Append, click and remove
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    });
});
