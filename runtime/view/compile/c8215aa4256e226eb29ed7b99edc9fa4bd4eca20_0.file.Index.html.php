<?php
/* Smarty version 3.1.46, created on 2026-09-18 18:34:52
  from '/workspace/app/View/User/Theme/Cartoon/Index/Index.html' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '3.1.46',
  'unifunc' => 'content_6aad13cc978190_94318945',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'c8215aa4256e226eb29ed7b99edc9fa4bd4eca20' => 
    array (
      0 => '/workspace/app/View/User/Theme/Cartoon/Index/Index.html',
      1 => 1789726831,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:./Header.html' => 1,
    'file:./Footer.html' => 1,
  ),
),false)) {
function content_6aad13cc978190_94318945 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_subTemplateRender("file:./Header.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
<main class="container py-4">
  <!-- 公告面板 -->
  <div class="panel">
    <div class="panel-header">
      <span class="icon"><i class="fa-duotone fa-regular fa-bullhorn"></i></span>
      <h6 class="panel-title"><?php echo t("公告");?>
</h6>
    </div>
    <div class="panel-body">
        <?php echo lang($_smarty_tpl->tpl_vars['config']->value['notice']);?>

    </div>
  </div>

  <!-- 购买面板 -->
  <div class="panel">
    <div class="panel-header">
      <span class="icon"><i class="fa-duotone fa-regular fa-cart-shopping"></i></span>
      <h6 class="panel-title"><?php echo t("购买");?>
</h6>
    </div>
    <div class="panel-body">
      <div class="mb-3">
        <div class="chip-list">
            <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['category']->value, 'cate');
$_smarty_tpl->tpl_vars['cate']->index = -1;
$_smarty_tpl->tpl_vars['cate']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['cate']->value) {
$_smarty_tpl->tpl_vars['cate']->do_else = false;
$_smarty_tpl->tpl_vars['cate']->index++;
$_smarty_tpl->tpl_vars['cate']->first = !$_smarty_tpl->tpl_vars['cate']->index;
$__foreach_cate_0_saved = $_smarty_tpl->tpl_vars['cate'];
?>
                <a data-id="<?php echo $_smarty_tpl->tpl_vars['cate']->value['id'];?>
" class="switch-category chip <?php if ($_smarty_tpl->tpl_vars['cate']->first && $_smarty_tpl->tpl_vars['categoryId']->value == 0) {?> is-primary <?php } elseif ($_smarty_tpl->tpl_vars['cate']->value['id'] == $_smarty_tpl->tpl_vars['categoryId']->value) {?> is-primary <?php }?>" href="javascript:void(0);"><?php if ($_smarty_tpl->tpl_vars['cate']->value['id'] != 'recommend') {?><span class="chip-icon" style="background: url('<?php echo $_smarty_tpl->tpl_vars['cate']->value['icon'];?>
') center/cover no-repeat;"></span><?php }
echo lang($_smarty_tpl->tpl_vars['cate']->value['name']);?>
</a>
            <?php
$_smarty_tpl->tpl_vars['cate'] = $__foreach_cate_0_saved;
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
        </div>
      </div>
      <div class="row item-list">
          <div class="item-message"><?php echo t("努力加载中..");?>
</div>
      </div>
    </div>
  </div>
</main>
<?php echo ready("/assets/user/controller/index/index.js");?>

<?php $_smarty_tpl->_subTemplateRender("file:./Footer.html", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
}
}
