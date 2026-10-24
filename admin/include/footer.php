
</main>
</div>
</div>

<!-- Bootstrap core JavaScript
================================================== -->
<!-- Placed at the end of the document so the pages load faster -->
<script src="js/jquery-3.7.1.slim.min.js"></script>
<script>window.jQuery || document.write('<script src="../../../../assets/js/vendor/jquery-slim.min.js"><\/script>')</script>
<script src="js/popper.min.js"></script>
<script src="../js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
<script src="js/jquery.dataTables.min.js?v=1.13.11"></script>
<script src="js/dataTables.bootstrap4.min.js?v=1.13.11"></script>
<script src="js/dropzone.js"></script>
<script>
  /*
   * Only touch plugins a page actually loaded. This footer is shared by every
   * admin page, and most of them do not include bootstrap-datepicker or a
   * DataTable — an unguarded call threw a TypeError inside $(ready) and stopped
   * the rest of this handler (tooltips included) on those pages. Feature-test
   * instead of assuming the page layout.
   */
    $(document).ready(function() {
        if ($.fn.datepicker) { $('#wedding_date').datepicker(); }
        if ($.fn.DataTable) {
            $('#example').DataTable();
            $('#features').DataTable();
        }
        $('[data-toggle="tooltip"]').tooltip();
    });
</script>

</body>
</html>

  <div id="dataModal" class="modal fade" tabindex="-1" role="dialog">  
      <div class="modal-dialog" role="document">  
           <div class="modal-content">  
                <div class="modal-header">  
                    <button type="button" class="close" data-dismiss="modal">&times;</button>  
                    <h4 class="modal-title">Employee Details</h4>  
                </div>  
                <div class="modal-body" id="package_detail">  
                </div>  
                <div class="modal-footer">  
                     <button type="button" class="btn btn-default" data-dismiss="modal">Closes</button>  
                </div>  
           </div>  
      </div>  
 </div>  


<script>
     $(document).on('click', '.view_data', function(){  
           var id = $(this).attr("id");  
           if(id != '')  
           {  
                $.ajax({  
                     url:"select.php",  
                     method:"POST",  
                     data:{id:id},  
                     success:function(data){  
                          $('#package_detail').html(data);  
                          $('#dataModal').modal('show');  
                     }  
                });  
           }            
      }); 
</script>

<script>
/* Gallery is only present on pages that load blueimp; a null #links used to
   throw here on every other page. */
var wpmsLinks = document.getElementById('links');
if (wpmsLinks && window.blueimp && window.blueimp.Gallery) {
    wpmsLinks.onclick = function (event) {
        event = event || window.event;
        var target = event.target || event.srcElement,
            link = target.src ? target.parentNode : target,
            options = {index: link, event: event},
            links = this.getElementsByTagName('a');
        blueimp.Gallery(links, options);
    };
}
</script>