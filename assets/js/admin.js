(function () {
  function parseCategoryIds(card) {
    var raw = card.getAttribute('data-category-ids') || '[]';
    try {
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch (error) {
      return [];
    }
  }

  function initBuilder() {
    var form = document.querySelector('.menuosaur-builder-form');
    if (!form) {
      return;
    }

    var categorySelect = form.querySelector('.menuosaur-category-select');
    var categoryTypeFilters = Array.prototype.slice.call(form.querySelectorAll('input[name="menuosaur_category_type_filter"]'));
    var picker = form.querySelector('.menuosaur-item-picker');
    var placeholder = form.querySelector('.menuosaur-picker-placeholder');
    var empty = form.querySelector('.menuosaur-picker-empty');
    var cards = Array.prototype.slice.call(form.querySelectorAll('.menuosaur-item-card'));

    if (!categorySelect || !picker) {
      return;
    }

    function getSelectedCategoryIds() {
      return Array.prototype.slice.call(categorySelect.selectedOptions || [])
        .map(function (option) {
          return option.value;
        })
        .filter(Boolean);
    }

    function getSelectedCategoryTypeFilter() {
      var selected = categoryTypeFilters.filter(function (input) {
        return input.checked;
      })[0];

      return selected ? selected.value : 'all';
    }

    function syncCategoryTypeFilter() {
      if (!categoryTypeFilters.length) {
        return;
      }

      var selectedType = getSelectedCategoryTypeFilter();
      Array.prototype.slice.call(categorySelect.options || []).forEach(function (option) {
        var optionType = option.getAttribute('data-category-type') || '';
        var visible = selectedType === 'all' || optionType === selectedType || option.selected;
        option.hidden = !visible;
        option.disabled = !visible && !option.selected;
      });
    }

    function setInputsDisabled(card, disabled) {
      card.querySelectorAll('input, select, textarea, button').forEach(function (input) {
        input.disabled = disabled;
      });
    }

    function updateOrder(container, selector, inputSelector) {
      var order = 1;
      Array.prototype.slice.call(container.querySelectorAll(selector)).forEach(function (item) {
        if (item.hidden) {
          return;
        }

        var input = item.querySelector(inputSelector);
        if (input && !input.disabled) {
          input.value = String(order);
        }
        order += 1;
      });
    }

    function getDragAfterElement(container, selector, y) {
      var draggableElements = Array.prototype.slice
        .call(container.querySelectorAll(selector + ':not(.is-dragging)'))
        .filter(function (element) {
          return !element.hidden;
        });

      return draggableElements.reduce(
        function (closest, child) {
          var box = child.getBoundingClientRect();
          var offset = y - box.top - box.height / 2;

          if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
          }

          return closest;
        },
        { offset: Number.NEGATIVE_INFINITY, element: null }
      ).element;
    }

    function initSortable(container, selector, inputSelector) {
      if (!container) {
        return;
      }

      var dragged = null;
      container.querySelectorAll(selector).forEach(function (item) {
        item.addEventListener('dragstart', function (event) {
          dragged = item;
          item.classList.add('is-dragging');
          if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.getAttribute('data-item-id') || 'menuosaur-sortable');
          }
        });

        item.addEventListener('dragend', function () {
          item.classList.remove('is-dragging');
          dragged = null;
          updateOrder(container, selector, inputSelector);
        });
      });

      container.addEventListener('dragover', function (event) {
        if (!dragged) {
          return;
        }

        event.preventDefault();
        var afterElement = getDragAfterElement(container, selector, event.clientY);
        if (!afterElement) {
          container.appendChild(dragged);
        } else {
          container.insertBefore(dragged, afterElement);
        }
        updateOrder(container, selector, inputSelector);
      });

      updateOrder(container, selector, inputSelector);
    }

    function syncCardSelection(card) {
      var itemCheckbox = card.querySelector('.menuosaur-item-check input[type="checkbox"]');
      var itemSelected = !!(itemCheckbox && itemCheckbox.checked && !itemCheckbox.disabled);
      var variationInputs = card.querySelectorAll('.menuosaur-variation-list input');

      card.classList.toggle('is-selected', itemSelected);
      variationInputs.forEach(function (input) {
        input.disabled = !itemSelected;
      });
      updateOrder(card.querySelector('.menuosaur-variation-list') || card, '.menuosaur-variation-row', '.menuosaur-variation-order-input');
    }

    function syncVisibleItems() {
      var selectedCategoryIds = getSelectedCategoryIds();
      var visibleCount = 0;

      cards.forEach(function (card) {
        var categoryIds = parseCategoryIds(card);
        var visible =
          selectedCategoryIds.length > 0 &&
          selectedCategoryIds.some(function (categoryId) {
            return categoryIds.indexOf(categoryId) !== -1;
          });

        card.hidden = !visible;
        setInputsDisabled(card, !visible);

        if (visible) {
          visibleCount += 1;
          syncCardSelection(card);
        }
      });

      if (placeholder) {
        placeholder.hidden = selectedCategoryIds.length > 0;
      }

      if (empty) {
        empty.hidden = selectedCategoryIds.length === 0 || visibleCount > 0;
      }

      updateOrder(picker, '.menuosaur-item-card', '.menuosaur-item-order-input');
    }

    cards.forEach(function (card) {
      var itemCheckbox = card.querySelector('.menuosaur-item-check input[type="checkbox"]');
      if (itemCheckbox) {
        itemCheckbox.addEventListener('change', function () {
          syncCardSelection(card);
        });
      }
    });

    categorySelect.addEventListener('change', syncVisibleItems);
    categoryTypeFilters.forEach(function (input) {
      input.addEventListener('change', function () {
        syncCategoryTypeFilter();
        syncVisibleItems();
      });
    });
    initSortable(picker, '.menuosaur-item-card', '.menuosaur-item-order-input');
    form.querySelectorAll('.menuosaur-variation-list').forEach(function (list) {
      initSortable(list, '.menuosaur-variation-row', '.menuosaur-variation-order-input');
    });
    form.addEventListener('submit', function () {
      updateOrder(picker, '.menuosaur-item-card', '.menuosaur-item-order-input');
      form.querySelectorAll('.menuosaur-variation-list').forEach(function (list) {
        updateOrder(list, '.menuosaur-variation-row', '.menuosaur-variation-order-input');
      });
    });
    syncCategoryTypeFilter();
    syncVisibleItems();
  }

  function initCopyFields() {
    document.querySelectorAll('.menuosaur-copy-field').forEach(function (field) {
      field.addEventListener('focus', function () {
        field.select();
      });
      field.addEventListener('click', function () {
        field.select();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initBuilder();
    initCopyFields();
  });
})();
