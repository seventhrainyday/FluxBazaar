<?php $page_title = '登录 · ' . SITE_NAME; ?>
<div class="card" style="max-width:420px;margin:40px auto">
  <h2>登录</h2>
  <form method="post">
    <input type="hidden" name="a" value="login">
    <label>用户名</label><input name="username" required>
    <label>密码</label><input type="password" name="password" required>
    <button class="btn" style="width:100%">登录</button>
  </form>
  <p class="mut">没有账号？<a href="?p=register">去注册</a></p>
</div>
