<?php declare(strict_types=1); ?>
<section class="dashboard-actions" aria-labelledby="quick-actions-title">
<h2 id="quick-actions-title">Quick actions</h2><div class="quick-action-grid">
<?php if(canRemit($user)): ?>
<a class="quick-action primary-action" href="?page=remittances#offering-report"><span class="quick-icon" aria-hidden="true">＋</span><div><strong>Start remittance</strong><span>Your church’s monthly offering</span></div><b aria-hidden="true">→</b></a>
<a class="quick-action" href="?page=remittances#payment-options"><span class="quick-icon" aria-hidden="true">⇄</span><div><strong>Record payment</strong><span>Transfer to provincial treasury</span></div><b aria-hidden="true">→</b></a>
<a class="quick-action" href="?page=profile"><span class="quick-icon" aria-hidden="true">♙</span><div><strong>My church details</strong></div><b aria-hidden="true">→</b></a>
<?php else: ?>
<a class="quick-action primary-action" href="?page=remittances"><span class="quick-icon" aria-hidden="true">⇄</span><div><strong>Review remittances</strong></div><b aria-hidden="true">→</b></a>
<?php if($admin): ?><a class="quick-action" href="?page=settings"><span class="quick-icon" aria-hidden="true">⚙</span><div><strong>Manage percentages</strong></div><b aria-hidden="true">→</b></a><?php endif ?>
<?php endif ?>
</div></section>
