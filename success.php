<?php
$_REQUEST['option']='com_virtuemart';
$_REQUEST['view']='pluginresponse';
$_REQUEST['task']='pluginresponsereceived';
$_REQUEST['pm'] = $_REQUEST['SHPPM'];
?>
<form action="../../../index.php" method="post" name="fname">
	<input type="hidden" name="option" value="com_virtuemart">
	<input type="hidden" name="view" value="pluginresponse">
	<input type="hidden" name="task" value="pluginresponsereceived">
	<input type="hidden" name="pm" value="<?php echo $_REQUEST['SHPPM']?>">
	<input type="hidden" name="on" value="<?php echo $_REQUEST['SHPON']?>">
</form>
<script>
document.forms.fname.submit();
</script>
