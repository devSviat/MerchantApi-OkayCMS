// Системний список товарів додає вибраний товар ще раз, якщо він уже є. Тут повтор прибирається, а наявний
// рядок підсвічується, щоб було видно, що товар уже у списку.
(function () {
    function dedupe(list, row) {
        var input = row.querySelector('input[type=hidden]');
        if (!input || input.value === '') {
            return;
        }
        var same = Array.prototype.filter.call(list.querySelectorAll('input[type=hidden]'), function (other) {
            return other !== input && other.value === input.value;
        });
        if (same.length === 0) {
            return;
        }
        row.remove();
        var existing = same[0].closest('.fn_row');
        existing.scrollIntoView({block: 'nearest'});
        existing.classList.remove('sviat_ma__flash');
        void existing.offsetWidth;
        existing.classList.add('sviat_ma__flash');
    }

    function watch(list) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1 && node.classList.contains('fn_row')) {
                        dedupe(list, node);
                    }
                });
            });
        }).observe(list, {childList: true});
    }

    // Прихована вкладка (display: none) не перетинає екран, тож запит піде лише тоді, коли «Товари» відкрили.
    function loadStats(box) {
        var observer = new IntersectionObserver(function (entries) {
            if (!entries.some(function (entry) { return entry.isIntersecting; })) {
                return;
            }
            observer.disconnect();
            fetch(box.dataset.url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }
                    return response.json();
                })
                .then(function (data) {
                    box.innerHTML = data.html;
                })
                .catch(function () {
                    var count = box.querySelector('.sviat_ma__count');
                    count.classList.add('is-failed');
                    count.querySelector('.sviat_ma__hint').textContent = box.dataset.error;
                });
        });
        observer.observe(box);
    }

    function init() {
        document.querySelectorAll('.sviat_ma__product_picker .okay_list_body').forEach(watch);
        document.querySelectorAll('.fn_ma_stats').forEach(loadStats);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
