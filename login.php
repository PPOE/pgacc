<?php
// Sends the login redirect, and the cookies of a new login, before any
// output. Returns true when the response is complete; otherwise
// page_login() renders the page.
function login_redirect()
{
  if (checklogin('id',false) > 0)
  {
    if (isset($_POST['target']))
    {
      header("Location: " . login_target($_POST['target']));
      return true;
    }
    return false;
  }
  $submit = isset($_POST['submit']) ? $_POST['submit'] : '';
  $name = isset($_POST['name']) ? $_POST['name'] : '';
  $pass = isset($_POST['pass']) ? $_POST['pass'] : '';
  if ($submit == "Anmeldung")
  {
    return login($name, $pass) > 0;
  }
  return false;
}

function page_login()
{
  if (checklogin('id',false) > 0)
  {
    echo '<h1>Login erfolgreich!</h1><br>';
    return;
  }
  $target = isset($_GET['target']) ? login_form_target($_GET['target']) : '';
  echo '<h1>Login erforderlich:</h1><br>';
  block_start();
  echo '<div class="main" id="default"><form class="login" action="index.php?action=login" method="POST"><div><label for="username_field" class="ui_field_label">Anmeldename</label> <input id="username_field" type="text" name="name" value="" /></div><div><label for="unique_hmyzqgytvlkhwnwshqbmbvvcvvlvdcmq" class="ui_field_label">Kennwort</label> <input id="unique_hmyzqgytvlkhwnwshqbmbvvcvvlvdcmq" type="password" name="pass" value="" /> <input name="target" type="hidden" value="'.html_escape($target).'" /></div><input value="Anmeldung" type="submit" name="submit" /></form></div>';
  block_end();

}
?>
