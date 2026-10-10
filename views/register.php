<?php $page_title = '注册 · ' . SITE_NAME; ?>
<div class="card" style="max-width:420px;margin:40px auto">
  <h2>注册</h2>
  <form method="post">
    <input type="hidden" name="a" value="register">
    <label>用户名（3-20 位字母数字下划线）</label><input name="username" required>
    <label>密码（至少 6 位）</label><input type="password" name="password" required>
    <button class="btn" style="width:100%">注册并登录</button>
  </form>
  <p class="mut">已有账号？<a href="?p=login">去登录</a></p>
</div>
