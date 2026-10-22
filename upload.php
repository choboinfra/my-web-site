<?php
$target_dir = "uploads/";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['fileToUpload'])) {
    $target_file = $target_dir . basename($_FILES["fileToUpload"]["name"]);
    if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
        echo "アップロード成功: <a href='".$target_file."'>".$target_file."</a><br><br>";
    } else {
        echo "アップロード失敗<br><br>";
    }
}
?>

<!DOCTYPE html>
<html>
<body>
<form action="upload.php" method="post" enctype="multipart/form-data">
    イメージ選択:
    <input type="file" name="fileToUpload" id="fileToUpload">
    <input type="submit" value="アップロード" name="submit">
</form>

<h3>アップロードされたイメージ</h3>
<?php
$files = scandir($target_dir);
foreach($files as $file) {
    if($file != '.' && $file != '..') {
        echo "<img src='uploads/$file' width='150' style='margin:5px;'>";
    }
}
?>
</body>
</html>
