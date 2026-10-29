document.addEventListener('DOMContentLoaded', function () {

    // Mobile menu toggle
    var toggle = document.getElementById('navToggle');
    var nav = document.getElementById('mainNav');
    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            nav.classList.toggle('open');
        });
    }

    // Confirm before destructive actions: <form data-confirm="Are you sure?">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // Dog detail page: click a thumbnail to swap the main photo
    var main = document.getElementById('mainPhoto');
    document.querySelectorAll('.thumb').forEach(function (t) {
        t.addEventListener('click', function () {
            if (main) main.src = t.src;
        });
    });

    // Live preview of photos chosen in the listing form (client-side checks too)
    var input = document.getElementById('photoInput');
    var preview = document.getElementById('photoPreview');
    if (input && preview) {
        input.addEventListener('change', function () {
            preview.innerHTML = '';
            var files = Array.prototype.slice.call(input.files);
            if (files.length > 5) {
                alert('You can upload a maximum of 5 photos.');
                input.value = '';
                return;
            }
            files.forEach(function (file) {
                if (file.size > 3 * 1024 * 1024) {
                    alert(file.name + ' is larger than 3 MB and will be skipped.');
                    return;
                }
                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                preview.appendChild(img);
            });
        });
    }

    // Registration form: check passwords match before submitting
    var reg = document.getElementById('registerForm');
    if (reg) {
        reg.addEventListener('submit', function (e) {
            var p = reg.querySelector('[name=password]').value;
            var c = reg.querySelector('[name=confirm]').value;
            if (p.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters.');
            } else if (p !== c) {
                e.preventDefault();
                alert('Passwords do not match.');
            }
        });
    }
});
