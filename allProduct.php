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

		$qry = "select * from Products";

		$sql = mysqli_query($con, $qry);

		$cnt = mysqli_num_rows($sql);



		if($cnt>0)
		{
			$temp['result'] = 1;

			$productdata = array();
			
			while($arr = mysqli_fetch_assoc($sql)) {
				
				$productdata[] = $arr;
			}

			$temp['productdata'] = $productdata;

		}
		else
		{
			$temp['result'] = 0;
		}
	


	echo json_encode($temp);

 ?>