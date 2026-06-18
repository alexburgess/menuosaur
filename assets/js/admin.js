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
    var selectedList = form.querySelector('.menuosaur-selected-items');
    var availableList = form.querySelector('.menuosaur-available-items');
    var searchInput = form.querySelector('.menuosaur-item-search');
    var placeholder = form.querySelector('.menuosaur-picker-placeholder');
    var empty = form.querySelector('.menuosaur-picker-empty');
    var selectedEmpty = form.querySelector('.menuosaur-selected-empty');
    var aspectRatioSelect = form.querySelector('#menuosaur_display_image_aspect_ratio');
    var customAspectRow = form.querySelector('.menuosaur-custom-aspect-row');
    var quantityToggle = form.querySelector('#menuosaur_enable_item_quantities');

    if (!categorySelect || !selectedList || !availableList) {
      return;
    }

    function getCards() {
      return Array.prototype.slice.call(form.querySelectorAll('.menuosaur-item-card'));
    }

    function getSelectedCategoryIds() {
      return Array.prototype.slice.call(categorySelect.selectedOptions || [])
        .map(function (option) {
          return option.value;
        })
        .filter(Boolean);
    }

    function getSearchQuery() {
      return searchInput ? searchInput.value.trim().toLowerCase() : '';
    }

    function isSelected(card) {
      var input = card.querySelector('.menuosaur-selected-item-input');
      return !!(input && input.checked);
    }

    function setCardInputs(card, selected) {
      var selectedInput = card.querySelector('.menuosaur-selected-item-input');
      var orderInput = card.querySelector('.menuosaur-item-order-input');
      var quantityInput = card.querySelector('.menuosaur-item-quantity-input');
      var variationInputs = card.querySelectorAll('.menuosaur-variation-list input');

      if (selectedInput) {
        selectedInput.checked = selected;
        selectedInput.disabled = !selected;
      }

      if (orderInput) {
        orderInput.disabled = !selected;
      }

      if (quantityInput) {
        quantityInput.disabled = !selected;
      }

      variationInputs.forEach(function (input) {
        input.disabled = !selected;
      });

      card.classList.toggle('is-selected', selected);
      if (selected) {
        card.setAttribute('draggable', 'true');
      } else {
        card.removeAttribute('draggable');
      }
    }

    function updateSelectedOrder() {
      var order = 1;
      Array.prototype.slice.call(selectedList.querySelectorAll('.menuosaur-item-card')).forEach(function (card) {
        var input = card.querySelector('.menuosaur-item-order-input');
        if (input) {
          input.value = String(order);
        }
        order += 1;
      });
    }

    function syncSelectedEmpty() {
      if (selectedEmpty) {
        selectedEmpty.hidden = selectedList.querySelectorAll('.menuosaur-item-card').length > 0;
      }
    }

    function syncQuantityControls() {
      form.classList.toggle('has-quantities-enabled', !!(quantityToggle && quantityToggle.checked));
    }

    function moveCard(card, selected) {
      setCardInputs(card, selected);
      if (selected) {
        selectedList.appendChild(card);
      } else {
        availableList.appendChild(card);
      }
      updateSelectedOrder();
      syncSelectedEmpty();
      syncVisibleItems();
    }

    function syncVisibleItems() {
      var selectedCategoryIds = getSelectedCategoryIds();
      var query = getSearchQuery();
      var visibleCount = 0;

      getCards().forEach(function (card) {
        if (isSelected(card)) {
          card.hidden = false;
          return;
        }

        var categoryIds = parseCategoryIds(card);
        var searchTarget = (card.getAttribute('data-search') || '').toLowerCase();
        var matchesSearch = query !== '' && searchTarget.indexOf(query) !== -1;
        var matchesCategory =
          query === '' &&
          selectedCategoryIds.length > 0 &&
          selectedCategoryIds.some(function (categoryId) {
            return categoryIds.indexOf(categoryId) !== -1;
          });
        var visible = matchesSearch || matchesCategory;

        card.hidden = !visible;
        if (visible) {
          visibleCount += 1;
        }
      });

      if (placeholder) {
        placeholder.hidden = query !== '' || selectedCategoryIds.length > 0;
      }

      if (empty) {
        empty.hidden = (query === '' && selectedCategoryIds.length === 0) || visibleCount > 0;
      }
    }

    function getDragAfterElement(container, y) {
      var draggableElements = Array.prototype.slice
        .call(container.querySelectorAll('.menuosaur-item-card:not(.is-dragging)'))
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

    function initSelectedSortable() {
      var dragged = null;

      selectedList.addEventListener('dragstart', function (event) {
        var card = event.target.closest('.menuosaur-item-card');
        if (!card || !isSelected(card)) {
          return;
        }

        dragged = card;
        card.classList.add('is-dragging');
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', card.getAttribute('data-item-id') || 'menuosaur-item');
        }
      });

      selectedList.addEventListener('dragend', function () {
        if (dragged) {
          dragged.classList.remove('is-dragging');
        }
        dragged = null;
        updateSelectedOrder();
      });

      selectedList.addEventListener('dragover', function (event) {
        if (!dragged) {
          return;
        }

        event.preventDefault();
        var afterElement = getDragAfterElement(selectedList, event.clientY);
        if (!afterElement) {
          selectedList.appendChild(dragged);
        } else {
          selectedList.insertBefore(dragged, afterElement);
        }
        updateSelectedOrder();
      });
    }

    form.addEventListener('click', function (event) {
      var addButton = event.target.closest('.menuosaur-add-item');
      var removeButton = event.target.closest('.menuosaur-remove-item');

      if (addButton) {
        moveCard(addButton.closest('.menuosaur-item-card'), true);
      }

      if (removeButton) {
        moveCard(removeButton.closest('.menuosaur-item-card'), false);
      }
    });

    categorySelect.addEventListener('change', syncVisibleItems);
    if (searchInput) {
      searchInput.addEventListener('input', syncVisibleItems);
    }
    if (aspectRatioSelect && customAspectRow) {
      aspectRatioSelect.addEventListener('change', function () {
        customAspectRow.hidden = aspectRatioSelect.value !== 'custom';
      });
      customAspectRow.hidden = aspectRatioSelect.value !== 'custom';
    }
    if (quantityToggle) {
      quantityToggle.addEventListener('change', syncQuantityControls);
    }

    form.addEventListener('submit', function () {
      updateSelectedOrder();
    });

    getCards().forEach(function (card) {
      setCardInputs(card, isSelected(card));
    });
    initSelectedSortable();
    updateSelectedOrder();
    syncSelectedEmpty();
    syncQuantityControls();
    syncVisibleItems();
  }

  function fallbackCopyText(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();

    try {
      document.execCommand('copy');
    } catch (error) {
      // Older browser fallback; the selected textarea still gives the user something to copy.
    }

    document.body.removeChild(textarea);
  }

  function markCopied(button) {
    var label = button.querySelector('span') || button;
    var original = button.getAttribute('data-original-label');
    if (!original) {
      original = label.textContent;
      button.setAttribute('data-original-label', original);
    }

    label.textContent = 'Copied';
    window.setTimeout(function () {
      label.textContent = original;
    }, 1400);
  }

  function initCopyButtons() {
    document.querySelectorAll('.menuosaur-copy-button').forEach(function (button) {
      button.addEventListener('click', function () {
        var text = button.getAttribute('data-menuosaur-copy') || '';
        if (!text) {
          return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(
            function () {
              markCopied(button);
            },
            function () {
              fallbackCopyText(text);
              markCopied(button);
            }
          );
          return;
        }

        fallbackCopyText(text);
        markCopied(button);
      });
    });
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
    initCopyButtons();
  });
})();
