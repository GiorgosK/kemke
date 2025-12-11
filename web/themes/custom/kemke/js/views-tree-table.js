(function (Drupal, once) {
  'use strict';

  const TRANSITION_MS = 240;

  function computeVisibility(rows) {
    const expandedByLevel = [];
    return rows.map((row) => {
      const level = Number(row.dataset.hierarchyLevel || 0);
      const parentExpanded = level === 0 ? true : expandedByLevel[level - 1] === true;
      const isExpanded = row.dataset.expanded !== 'false';
      expandedByLevel[level] = parentExpanded && isExpanded;
      return parentExpanded;
    });
  }

  function applyVisibility(table, animate) {
    const rows = Array.from(table.querySelectorAll('tr[data-hierarchy-level]'));
    const visibility = computeVisibility(rows);

    rows.forEach((row, index) => {
      const shouldShow = visibility[index];
      const isCurrentlyHidden = row.style.display === 'none' || row.dataset.visible === 'false';

      const toggle = row.querySelector('.views-tree-toggle');
      if (toggle) {
        const isExpanded = row.dataset.expanded !== 'false';
        toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      }

      if (shouldShow && isCurrentlyHidden) {
        row.dataset.visible = 'true';
        row.style.display = 'table-row';
        if (animate) {
          row.classList.add('is-entering');
          requestAnimationFrame(() => {
            row.classList.remove('is-entering');
          });
        } else {
          row.classList.remove('is-entering', 'is-exiting');
        }
      } else if (!shouldShow && !isCurrentlyHidden) {
        row.dataset.visible = 'false';
        if (animate) {
          row.classList.add('is-exiting');
          setTimeout(() => {
            row.style.display = 'none';
            row.classList.remove('is-exiting');
          }, TRANSITION_MS);
        } else {
          row.style.display = 'none';
          row.classList.remove('is-entering', 'is-exiting');
        }
      }
    });
  }

  Drupal.behaviors.kemkeViewsTreeTable = {
    attach(context) {
      once('kemke-views-tree-table', 'table.views-table', context).forEach((table) => {
        const rows = table.querySelectorAll('tr[data-hierarchy-level]');
        if (!rows.length) {
          return;
        }

        rows.forEach((row) => {
          const hasChildren = row.dataset.hasChildren === 'true';
          if (!row.dataset.expanded) {
            row.dataset.expanded = hasChildren ? 'false' : 'true';
          }
        });

        applyVisibility(table, false);

        table.querySelectorAll('.views-tree-toggle').forEach((button) => {
          button.addEventListener('click', () => {
            const row = button.closest('tr[data-hierarchy-level]');
            if (!row) {
              return;
            }
            const isExpanded = row.dataset.expanded === 'true';
            row.dataset.expanded = isExpanded ? 'false' : 'true';
            applyVisibility(table, true);
          });
        });
      });
    },
  };
})(Drupal, once);
