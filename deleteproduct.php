<?php 
	include_once("config.php");
	$temp = array();

	if($con)
	{
		$temp['connection'] = 1;
	}
	else
	{
		$temp['connection'] = 0;
	}


	$id = $_POST['id'];

		

		$qry = "delete from Products where ID = '$id'";

		$sql = mysqli_query($con, $qry);

		if($sql)
		{
			$temp['result'] = 1;
		}
		else
		{
			$temp['result'] = 0;
		}
	


	echo json_encode($temp);

 ?>