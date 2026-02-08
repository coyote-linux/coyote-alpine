<p><a href="/tools">&larr; Back to Tools</a></p>

<div class="card">
    <h3>Generator Settings</h3>
    <div class="form-row">
        <div class="form-group">
            <label for="pw-length">Password Length</label>
            <input type="number" id="pw-length" value="16" min="8" max="128">
        </div>
        <div class="form-group">
            <label for="pw-count">Number of Passwords</label>
            <select id="pw-count">
                <option value="1">1</option>
                <option value="5" selected>5</option>
                <option value="10">10</option>
                <option value="20">20</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label>Password Type</label>
        <div class="radio-group">
            <label class="radio-label">
                <input type="radio" name="pw-type" value="complex" checked>
                <div>
                    <span class="radio-text">Complex</span>
                    <span class="radio-desc">Random characters from selected character sets</span>
                </div>
            </label>
            <label class="radio-label">
                <input type="radio" name="pw-type" value="readable">
                <div>
                    <span class="radio-text">Readable</span>
                    <span class="radio-desc">Alternating syllables with digits (e.g. boku42nare78)</span>
                </div>
            </label>
            <label class="radio-label">
                <input type="radio" name="pw-type" value="pronounceable">
                <div>
                    <span class="radio-text">Pronounceable</span>
                    <span class="radio-desc">Consonant-vowel pseudo-words (e.g. VokaLimuTepa)</span>
                </div>
            </label>
        </div>
    </div>

    <div id="complexity-options">
        <label>Character Sets</label>
        <div class="form-row">
            <div class="form-group-inline">
                <label><input type="checkbox" id="pw-upper" checked> Uppercase (A-Z)</label>
            </div>
            <div class="form-group-inline">
                <label><input type="checkbox" id="pw-lower" checked> Lowercase (a-z)</label>
            </div>
            <div class="form-group-inline">
                <label><input type="checkbox" id="pw-digits" checked> Digits (0-9)</label>
            </div>
            <div class="form-group-inline">
                <label><input type="checkbox" id="pw-symbols" checked> Symbols (!@#$...)</label>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" id="pw-generate">Generate</button>
        <button class="btn" id="pw-copy-all" style="display:none;">Copy All</button>
    </div>
</div>

<div class="card" id="pw-results-card" style="display:none;">
    <div class="card-header">
        <h3>Generated Passwords</h3>
    </div>
    <div id="pw-results" class="password-list"></div>
</div>

<script>
(function() {
    var UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    var LOWER = 'abcdefghijklmnopqrstuvwxyz';
    var DIGITS = '0123456789';
    var SYMBOLS = '!@#$%^&*()-_=+[]{}|;:,.<>?';
    var CONSONANTS = 'bcdfghjklmnprstvwz';
    var VOWELS = 'aeiou';

    function secureRandom(max) {
        var arr = new Uint32Array(1);
        crypto.getRandomValues(arr);
        return arr[0] % max;
    }

    function secureChoice(str) {
        return str[secureRandom(str.length)];
    }

    function generateComplex(length) {
        var charset = '';
        if (document.getElementById('pw-upper').checked) charset += UPPER;
        if (document.getElementById('pw-lower').checked) charset += LOWER;
        if (document.getElementById('pw-digits').checked) charset += DIGITS;
        if (document.getElementById('pw-symbols').checked) charset += SYMBOLS;
        if (charset.length === 0) charset = UPPER + LOWER + DIGITS;

        var pw = '';
        for (var i = 0; i < length; i++) {
            pw += secureChoice(charset);
        }
        return pw;
    }

    function generateReadable(length) {
        var consonants = 'bdfghjklmnprstvz';
        var vowels = 'aeiou';
        var pw = '';
        var syllableCount = 0;

        while (pw.length < length) {
            pw += secureChoice(consonants);
            if (pw.length < length) pw += secureChoice(vowels);
            if (pw.length < length) pw += secureChoice(consonants);
            syllableCount++;

            if (syllableCount % 2 === 0 && pw.length < length - 1) {
                var d1 = secureChoice(DIGITS);
                var d2 = secureChoice(DIGITS);
                pw += d1 + d2;
            }
        }

        return pw.substring(0, length);
    }

    function generatePronounceable(length) {
        var pw = '';

        while (pw.length < length) {
            var c = secureChoice(CONSONANTS);
            var v = secureChoice(VOWELS);

            if (pw.length % 6 < 3) {
                c = c.toUpperCase();
            }

            pw += c + v;
        }

        return pw.substring(0, length);
    }

    function generate() {
        var length = parseInt(document.getElementById('pw-length').value, 10);
        if (isNaN(length) || length < 8) length = 8;
        if (length > 128) length = 128;

        var count = parseInt(document.getElementById('pw-count').value, 10);
        var type = document.querySelector('input[name="pw-type"]:checked').value;
        var passwords = [];

        for (var i = 0; i < count; i++) {
            if (type === 'complex') {
                passwords.push(generateComplex(length));
            } else if (type === 'readable') {
                passwords.push(generateReadable(length));
            } else {
                passwords.push(generatePronounceable(length));
            }
        }

        var container = document.getElementById('pw-results');
        container.innerHTML = '';

        passwords.forEach(function(pw) {
            var item = document.createElement('div');
            item.className = 'password-item';

            var text = document.createElement('code');
            text.className = 'password-text';
            text.textContent = pw;

            var btn = document.createElement('button');
            btn.className = 'btn btn-small copy-btn';
            btn.textContent = 'Copy';
            btn.addEventListener('click', function() {
                navigator.clipboard.writeText(pw).then(function() {
                    btn.textContent = 'Copied';
                    setTimeout(function() { btn.textContent = 'Copy'; }, 1500);
                });
            });

            item.appendChild(text);
            item.appendChild(btn);
            container.appendChild(item);
        });

        document.getElementById('pw-results-card').style.display = 'block';
        document.getElementById('pw-copy-all').style.display = 'inline-flex';

        document.getElementById('pw-copy-all').onclick = function() {
            var all = passwords.join('\n');
            navigator.clipboard.writeText(all).then(function() {
                var btn = document.getElementById('pw-copy-all');
                btn.textContent = 'Copied';
                setTimeout(function() { btn.textContent = 'Copy All'; }, 1500);
            });
        };
    }

    function updateComplexityVisibility() {
        var type = document.querySelector('input[name="pw-type"]:checked').value;
        document.getElementById('complexity-options').style.display = type === 'complex' ? 'block' : 'none';
    }

    document.querySelectorAll('input[name="pw-type"]').forEach(function(radio) {
        radio.addEventListener('change', updateComplexityVisibility);
    });

    document.getElementById('pw-generate').addEventListener('click', generate);
    updateComplexityVisibility();
})();
</script>
