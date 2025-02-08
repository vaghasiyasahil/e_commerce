<?
    echo "Start";
    include_once("config.php");
    $select="select * from Products";
    $sql = mysqli_query($con, $qry);
    while($row=mysqli_fetch_array($sql)){
        echo $row['PRO_NAME'];
        echo $row['PRO_DES'];
    }
    echo "end";
?>