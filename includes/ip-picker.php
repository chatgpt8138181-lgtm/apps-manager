<?php
declare(strict_types=1);

/*
 * One picker for the whole page: a dialog holding the IP list as a table, with
 * a search box over it. The list is written once, however many rows use it,
 * because a hundred IPs beside every app would weigh the page down.
 */

function render_ip_picker(array $pool): void
{
    if (!$pool) {
        return;
    }
    ?>
    <dialog class="ip-modal" id="ip-picker" aria-label="Pick IPs">
        <div class="ip-modal-head">
            <div>
                <h3 class="ip-modal-title">Pick IPs</h3>
                <p class="ip-modal-for" data-role="for"></p>
            </div>
            <button class="ip-modal-x" type="button" data-close aria-label="Close">&times;</button>
        </div>

        <div class="ip-modal-tools">
            <input type="search" class="ip-modal-search" placeholder="Search a name, address, provider, country or city"
                   aria-label="Search IPs" autocomplete="off">
            <span class="ip-modal-count" data-role="count">0 picked</span>
        </div>

        <div class="ip-modal-body">
            <table class="ip-modal-table">
                <thead>
                <tr>
                    <th class="col-pick"><input type="checkbox" class="ip-modal-all" aria-label="Pick everything shown"></th>
                    <th>Name</th>
                    <th>IP</th>
                    <th>Provider</th>
                    <th>Country</th>
                    <th>City</th>
                    <th>Today</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pool as $entry): ?>
                    <?php
                    $find = mb_strtolower(implode(' ', array_filter([
                        (string) $entry['name'],
                        (string) $entry['ip'],
                        (string) ($entry['provider'] ?? ''),
                        (string) ($entry['country'] ?? ''),
                        (string) ($entry['city'] ?? ''),
                    ])));
                    ?>
                    <tr data-id="<?= (int) $entry['id'] ?>" data-find="<?= h($find) ?>">
                        <td class="col-pick">
                            <input type="checkbox" value="<?= (int) $entry['id'] ?>" aria-label="<?= h($entry['name']) ?>">
                        </td>
                        <td class="ip-modal-name"><?= h($entry['name']) ?></td>
                        <td><code><?= h($entry['ip']) ?></code></td>
                        <td><?= $entry['provider'] ? h((string) $entry['provider']) : '&mdash;' ?></td>
                        <td><?= $entry['country'] ? h((string) $entry['country']) : '&mdash;' ?></td>
                        <td><?= $entry['city'] ? h((string) $entry['city']) : '&mdash;' ?></td>
                        <td class="ip-modal-today"><span class="badge badge-green">Added</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="empty block ip-modal-empty" hidden>No IP matches that.</p>
        </div>

        <div class="ip-modal-foot">
            <button class="btn primary" type="button" data-done disabled>Add picked IPs</button>
            <button class="btn" type="button" data-close>Cancel</button>
        </div>
    </dialog>

    <script>
    /* The dialog is shared; whoever opened it hears back through an event. */
    (() => {
        const modal = document.getElementById('ip-picker');
        if (!modal) {
            return;
        }

        const search = modal.querySelector('.ip-modal-search');
        const rows = [...modal.querySelectorAll('tbody tr')];
        const all = modal.querySelector('.ip-modal-all');
        const count = modal.querySelector('[data-role="count"]');
        const forLabel = modal.querySelector('[data-role="for"]');
        const done = modal.querySelector('[data-done]');
        const empty = modal.querySelector('.ip-modal-empty');
        const box = (row) => row.querySelector('input[type="checkbox"]');
        let opener = null;

        const shown = () => rows.filter((row) => !row.hidden);

        const refresh = () => {
            const picked = rows.filter((row) => box(row).checked).length;
            count.textContent = picked + ' picked';
            done.disabled = picked === 0;
            const visible = shown();
            all.checked = visible.length > 0 && visible.every((row) => box(row).checked);
            empty.hidden = visible.length > 0;
        };

        const filter = () => {
            const wanted = search.value.trim().toLowerCase();
            rows.forEach((row) => {
                row.hidden = wanted !== '' && !row.dataset.find.includes(wanted);
            });
            refresh();
        };

        search.addEventListener('input', filter);

        all.addEventListener('change', () => {
            shown().forEach((row) => { box(row).checked = all.checked; });
            refresh();
        });

        rows.forEach((row) => box(row).addEventListener('change', refresh));

        modal.querySelectorAll('[data-close]').forEach((button) => {
            button.addEventListener('click', () => modal.close());
        });

        document.querySelectorAll('.ip-pick-open').forEach((button) => {
            button.addEventListener('click', () => {
                opener = button;
                const already = (button.dataset.on || '').split(',').filter(Boolean);
                rows.forEach((row) => {
                    box(row).checked = false;
                    row.classList.toggle('is-added', already.includes(row.dataset.id));
                });
                search.value = '';
                filter();
                forLabel.textContent = button.dataset.name || '';
                modal.showModal();
                search.focus();
            });
        });

        done.addEventListener('click', () => {
            const ids = rows.filter((row) => box(row).checked).map((row) => box(row).value);
            if (!ids.length || !opener) {
                return;
            }
            modal.close();
            document.dispatchEvent(new CustomEvent('ip-picker:done', {
                detail: { opener: opener, ids: ids },
            }));
        });
    })();
    </script>
    <?php
}
