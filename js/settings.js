/**
 * Admin settings form handler for chamade_talk.
 */
document.addEventListener('DOMContentLoaded', function () {
    var appId = 'chamade_talk';

    var form = document.getElementById('chamade-settings-form');
    var status = document.getElementById('chamade-save-status');

    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        status.textContent = t(appId, 'Saving…');

        var data = new FormData(form);
        var body = {};
        data.forEach(function (value, key) {
            body[key] = value;
        });

        fetch(OC.generateUrl('/apps/' + appId + '/settings'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken,
            },
            body: JSON.stringify(body),
        })
        .then(function (resp) {
            if (resp.ok) {
                status.textContent = t(appId, 'Saved');
                setTimeout(function () { status.textContent = ''; }, 2000);
            } else {
                status.textContent = t(appId, 'Error saving settings');
            }
        })
        .catch(function () {
            status.textContent = t(appId, 'Network error');
        });
    });
});
