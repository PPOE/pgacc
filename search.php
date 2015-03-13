<?php
function page_search($rights)
{
  $member = intval($_GET['member']);
  header("Location: https://finanzen.piratenpartei.at/index.php?action=all&filter_member_id=$member");
}
?>
