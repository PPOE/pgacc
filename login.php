<?php
function page_login()
{
  if (checklogin('id',false) > 0)
  {
    echo '<h1>Login erfolgreich!</h1><br>';
    return;
  }
  $submit = isset($_POST['submit']) ? $_POST['submit'] : '';
  $name = isset($_POST['name']) ? $_POST['name'] : '';
  $pass = isset($_POST['pass']) ? $_POST['pass'] : '';
  if ($submit == "Anmeldung")
  {
    login($name, $pass);
  }
  echo '<h1>Login erforderlich:</h1><br>';
  block_start();
  echo '<div class="main" id="default"><form class="login" action="index.php?action=login" method="POST"><div><label for="username_field" class="ui_field_label">Anmeldename</label> <input id="username_field" type="text" name="name" value="" /></div><div><label for="unique_hmyzqgytvlkhwnwshqbmbvvcvvlvdcmq" class="ui_field_label">Kennwort</label> <input id="unique_hmyzqgytvlkhwnwshqbmbvvcvvlvdcmq" type="password" name="pass" value="" /></div><input value="Anmeldung" type="submit" name="submit" /></form></div>';
  block_end();

}
?>
