<?php
/**
 * TO BE INCLUDED IN THE <HEAD> IF THERE IS NO CURRENT SESSION
 */
?>

<style>
#wpadminbar {
    display: none !important;
}
</style>

<script src="https://cdn.auth0.com/js/auth0/9.18/auth0.min.js"></script>
<script type="text/javascript">
(function () {
    var webAuth = new auth0.WebAuth({
        domain: 'login.bcc.no',
        clientID: '<?= $clientID ?>',
        scope: '<?= $scope ?>',
        responseType: 'id_token',
        responseMode: 'fragment',
        redirectUri: '<?= $redirectUrl ?>'
    });
    setTimeout(function () {
        webAuth.checkSession({prompt: 'none'}, function (err, authResult) {
            if (authResult) {
                window.location = '/login';
            }
            if (err) {
                console.warn(err)
            }
        });
    }, 500)
})()
</script>