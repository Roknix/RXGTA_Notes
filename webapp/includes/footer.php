<?php $jsVer = @filemtime(__DIR__ . '/../assets/app.js') ?: time(); ?>
<script src="/assets/app.js?v=<?= (int)$jsVer ?>"></script>
</body>
</html>
