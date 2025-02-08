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
	$name = $_POST['name'];
	$price = $_POST['price'];
	$description = $_POST['description'];
	$imagedata = $_POST['imagedata'];
	// $imagename = $_POST['imagename'];

		$realimage = base64_decode($imagedata);

		$imagename = "ProductImage/".$name.rand(0,10000).rand(0,10000).".jpg";

		file_put_contents($imagename, $realimage);

		$qry = "update products set PRO_NAME = '$name' , PRO_PRICE = '$price' , PRO_DES ='$description' , PRO_IMAGE = '$imagename' where ID = '$id' ";

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
