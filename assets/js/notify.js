(function (global) {
  'use strict';

  var popupOpen = false;
  var loadingOpen = false;
  var loadingCloseResolve = null;
  var fallbackOverlay = null;
  var fallbackResolve = null;
  var fallbackTimer = null;
  var fallbackFocusTarget = null;
  var fallbackKeyHandler = null;
  var reducedMotion = global.matchMedia('(prefers-reduced-motion: reduce)');

  function focusTarget(options) {
    if (options && options.focusTarget) return options.focusTarget;
    return document.getElementById('studentScan') || document.activeElement;
  }

  function restoreFocus(target) {
    if (!target) return;
    var element = typeof target === 'string' ? document.querySelector(target) : target;
    if (element && typeof element.focus === 'function') {
      global.requestAnimationFrame(function () { element.focus(); });
    }
  }

  function detailText(detail) {
    return typeof detail === 'string' && detail.trim() ? detail.trim() : undefined;
  }

  function closeFallback(result) {
    if (!fallbackOverlay) return;
    global.clearTimeout(fallbackTimer);
    fallbackTimer = null;
    var overlay = fallbackOverlay;
    var target = fallbackFocusTarget;
    var resolve = fallbackResolve;
    if (fallbackKeyHandler) {
      document.removeEventListener('keydown', fallbackKeyHandler, true);
      fallbackKeyHandler = null;
    }
    fallbackOverlay = null;
    fallbackFocusTarget = null;
    fallbackResolve = null;
    popupOpen = false;
    loadingOpen = false;
    overlay.remove();
    restoreFocus(target);
    if (resolve) resolve(result || { isDismissed: true });
  }

  function fallbackFire(config, target) {
    if (popupOpen && fallbackOverlay) return Promise.resolve({ isDismissed: true });

    popupOpen = true;
    fallbackFocusTarget = target;
    return new Promise(function (resolve) {
      fallbackResolve = resolve;
      var overlay = document.createElement('div');
      overlay.className = 'notify-fallback-backdrop';
      var popup = document.createElement('section');
      popup.className = 'notify-fallback-popup';
      popup.setAttribute('role', 'dialog');
      popup.setAttribute('aria-modal', 'true');
      popup.setAttribute('aria-labelledby', 'notify-fallback-title');
      popup.tabIndex = -1;

      if (config.icon) {
        var icon = document.createElement('div');
        icon.className = 'notify-fallback-icon notify-fallback-icon-' + config.icon;
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = config.icon === 'success' ? '✓' :
          (config.icon === 'error' ? '×' :
            (config.icon === 'warning' ? '!' : (config.icon === 'question' ? '?' : 'i')));
        popup.appendChild(icon);
      }

      var title = document.createElement('h2');
      title.className = 'notify-fallback-title';
      title.id = 'notify-fallback-title';
      title.textContent = config.title || '';
      popup.appendChild(title);

      var detail = detailText(config.text);
      if (detail) {
        var description = document.createElement('p');
        description.className = 'notify-fallback-detail';
        description.textContent = detail;
        popup.appendChild(description);
      }

      if (config.showLoaderOnConfirm) {
        var spinner = document.createElement('span');
        spinner.className = 'notify-fallback-spinner';
        spinner.setAttribute('aria-label', 'Loading');
        popup.appendChild(spinner);
      }

      if (config.timerProgressBar) {
        var progress = document.createElement('span');
        progress.className = 'notify-fallback-timer';
        popup.appendChild(progress);
      }

      var actions = document.createElement('div');
      actions.className = 'notify-fallback-actions';
      function addButton(label, className, result) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.addEventListener('click', function () { closeFallback(result); });
        actions.appendChild(button);
        return button;
      }

      var initialFocus = null;
      if (config.showCancelButton) {
        var cancelButton = addButton(config.cancelButtonText || 'Cancel', 'notify-fallback-cancel', { isDismissed: true });
        var confirmButton = addButton(config.confirmButtonText || 'Confirm',
          'notify-fallback-confirm' + (config.customClass && String(config.customClass.confirmButton || '').indexOf('notify-confirm-danger') >= 0 ? ' notify-fallback-danger' : ''),
          { isConfirmed: true });
        initialFocus = config.focusCancel ? cancelButton : confirmButton;
      } else if (config.showConfirmButton !== false) {
        initialFocus = addButton(config.confirmButtonText || 'OK', 'notify-fallback-confirm', { isConfirmed: true });
      }
      if (actions.childElementCount) popup.appendChild(actions);

      overlay.appendChild(popup);
      document.body.appendChild(overlay);
      fallbackOverlay = overlay;
      global.requestAnimationFrame(function () {
        if (initialFocus) initialFocus.focus();
        else popup.focus();
      });

      overlay.addEventListener('click', function (event) {
        if (event.target === overlay && config.allowOutsideClick) closeFallback({ isDismissed: true });
      });
      fallbackKeyHandler = function (event) {
        if (event.key === 'Escape' && config.allowEscapeKey) {
          event.preventDefault();
          closeFallback({ isDismissed: true });
        } else if (event.key === 'Enter' && config.allowEnterKey && !config.showCancelButton) {
          event.preventDefault();
          closeFallback(config.showConfirmButton === false ? { isDismissed: true } : { isConfirmed: true });
        } else if (event.key === 'Tab') {
          var buttons = popup.querySelectorAll('button');
          if (!buttons.length) {
            event.preventDefault();
          } else if (event.shiftKey && document.activeElement === buttons[0]) {
            event.preventDefault();
            buttons[buttons.length - 1].focus();
          } else if (!event.shiftKey && document.activeElement === buttons[buttons.length - 1]) {
            event.preventDefault();
            buttons[0].focus();
          }
        }
      };
      document.addEventListener('keydown', fallbackKeyHandler, true);

      if (config.timer) {
        fallbackTimer = global.setTimeout(function () {
          closeFallback({ isDismissed: true, dismiss: 'timer' });
        }, config.timer);
      }
    });
  }

  function fire(options, target) {
    if (!global.Swal) return fallbackFire(options, target);
    if (popupOpen && global.Swal.isVisible()) return Promise.resolve({ isDismissed: true });
    popupOpen = true;
    var existingClose = options.didClose;
    options.didClose = function () {
      popupOpen = false;
      restoreFocus(target);
      if (typeof existingClose === 'function') existingClose();
    };
    options.animation = !reducedMotion.matches;
    return global.Swal.fire(options);
  }

  function message(type, title, detail, options) {
    options = options || {};
    if (typeof title !== 'string' || !title.trim()) return Promise.resolve({ isDismissed: true });
    var target = focusTarget(options);
    var config = {
      title: title,
      text: detailText(detail),
      icon: type,
      customClass: {
        popup: 'notify-popup',
        title: 'notify-title',
        htmlContainer: 'notify-detail',
        icon: 'notify-icon notify-icon-' + type,
        actions: 'notify-actions',
        confirmButton: 'notify-confirm-button',
        timerProgressBar: 'notify-timer-progress'
      },
      buttonsStyling: false,
      focusConfirm: false,
      returnFocus: false
    };
    if (type === 'success') {
      config.showConfirmButton = false;
      config.timer = 2200;
      config.timerProgressBar = true;
      config.allowEscapeKey = true;
      config.allowEnterKey = true;
      config.allowOutsideClick = true;
      var successEnterHandler = function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          global.Swal.close();
        }
      };
      config.didOpen = function () {
        document.addEventListener('keydown', successEnterHandler, true);
      };
      config.didClose = function () {
        document.removeEventListener('keydown', successEnterHandler, true);
      };
    } else {
      config.showConfirmButton = true;
      config.confirmButtonText = 'OK';
      config.allowEscapeKey = true;
      config.allowEnterKey = true;
      config.allowOutsideClick = false;
    }
    Object.assign(config, options.swal || {});
    return fire(config, target);
  }

  global.notify = {
    success: function (title, detail, options) {
      return message('success', title, detail, options);
    },
    error: function (title, detail, options) {
      return message('error', title, detail, options);
    },
    warning: function (title, detail, options) {
      return message('warning', title, detail, options);
    },
    info: function (title, detail, options) {
      return message('info', title, detail, options);
    },
    confirm: function (options) {
      options = options || {};
      var target = focusTarget(options);
      var danger = options.danger === true;
      return fire({
        title: options.title || 'Are you sure?',
        text: detailText(options.text),
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        allowEscapeKey: true,
        allowEnterKey: true,
        confirmButtonText: options.confirmText || 'Confirm',
        cancelButtonText: options.cancelText || 'Cancel',
        buttonsStyling: false,
        focusCancel: true,
        returnFocus: false,
        customClass: {
          popup: 'notify-popup',
          title: 'notify-title',
          htmlContainer: 'notify-detail',
          icon: danger ? 'notify-icon notify-icon-warning' : 'notify-icon notify-icon-info',
          actions: 'notify-actions',
          confirmButton: danger ? 'notify-confirm-button notify-confirm-danger' : 'notify-confirm-button',
          cancelButton: 'notify-cancel-button'
        },
        didOpen: function () {
          var cancel = global.Swal.getCancelButton();
          if (cancel) cancel.focus();
        }
      }, target).then(function (result) {
        return result.isConfirmed === true;
      });
    },
    loading: function (title) {
      if (!global.Swal) {
        if (popupOpen) return Promise.resolve();
        popupOpen = true;
        loadingOpen = true;
        fallbackFire({
          title: title || 'Please wait…',
          showConfirmButton: false,
          showLoaderOnConfirm: true,
          allowEscapeKey: false,
          allowEnterKey: false,
          allowOutsideClick: false
        }, focusTarget({}));
        return Promise.resolve();
      }
      if (popupOpen && global.Swal.isVisible()) return Promise.resolve();
      popupOpen = true;
      loadingOpen = true;
      var target = focusTarget({});
      return global.Swal.fire({
        title: title || 'Please wait…',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: function () { global.Swal.showLoading(); },
        didClose: function () {
          popupOpen = false;
          loadingOpen = false;
          restoreFocus(target);
          if (loadingCloseResolve) {
            loadingCloseResolve();
            loadingCloseResolve = null;
          }
        },
        customClass: { popup: 'notify-popup', title: 'notify-title', loader: 'notify-loader' },
        buttonsStyling: false,
        returnFocus: false,
        animation: !reducedMotion.matches
      });
    },
    close: function () {
      if (fallbackOverlay && loadingOpen) {
        closeFallback({ isDismissed: true });
        return Promise.resolve();
      }
      if (!global.Swal) return Promise.resolve();
      if (!loadingOpen || !global.Swal.isVisible()) {
        popupOpen = false;
        loadingOpen = false;
        return Promise.resolve();
      }
      return new Promise(function (resolve) {
        loadingCloseResolve = resolve;
        global.Swal.close();
      });
    }
  };
}(window));
