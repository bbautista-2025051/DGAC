/* =============================================================================
   DGAC · Registro de Drones
   Validación de formulario, llamadas al backend y confirmación visual.
   Sin dependencias externas.
   ============================================================================= */

(() => {
  'use strict';

  const CONFIG = {
    // Si la página se sirve desde otro origen, defina antes de este script:
    // window.DGAC_DRONES_CONFIG = { apiBase: 'https://mi-servidor/registro-drones/api' };
    apiBase: (window.DGAC_DRONES_CONFIG && window.DGAC_DRONES_CONFIG.apiBase) || 'api',
    storageKey: 'dgac_drones_ultimo_registro',
    requestTimeout: 45000,
  };

  const API_BASE = String(CONFIG.apiBase).replace(/\/+$/, '');

  const form = document.getElementById('registro-form');
  const panelForm = document.getElementById('panel-form');
  const panelSuccess = document.getElementById('panel-success');
  const submitButton = document.getElementById('btn-submit');
  const formAlert = document.getElementById('form-alert');
  const formAlertText = document.getElementById('form-alert-text');

  const successTitle = document.getElementById('success-title');
  const successMessage = document.getElementById('success-message');
  const successCodigo = document.getElementById('success-codigo');
  const successFecha = document.getElementById('success-fecha');
  const successDron = document.getElementById('success-dron');
  const successSerie = document.getElementById('success-serie');
  const successPropietario = document.getElementById('success-propietario');
  const successCorreo = document.getElementById('success-correo');
  const successEstado = document.getElementById('success-estado');
  const successWarning = document.getElementById('success-warning');
  const successWarningText = document.getElementById('success-warning-text');
  const successInfo = document.getElementById('success-info');
  const successInfoText = document.getElementById('success-info-text');
  const pdfButton = document.getElementById('btn-pdf');
  const retryButton = document.getElementById('btn-retry');
  const newButton = document.getElementById('btn-new');
  const retryNote = document.getElementById('success-retry-note');

  const loginButton = document.getElementById('btn-login');
  const loginLabel = document.getElementById('login-label');
  const sessionPanel = document.getElementById('session-panel');
  const sessionUserName = document.getElementById('session-user-name');
  const sessionUserRole = document.getElementById('session-user-role');
  const sessionUserEmail = document.getElementById('session-user-email');
  const loginModal = document.getElementById('login-modal');
  const loginForm = document.getElementById('login-form');
  const loginAlert = document.getElementById('login-alert');
  const loginAlertText = document.getElementById('login-alert-text');
  const loginEmail = document.getElementById('login_correo');
  const loginPassword = document.getElementById('login_password');
  const loginEmailError = document.getElementById('login_correo-error');
  const loginPasswordError = document.getElementById('login_password-error');
  const loginClose = document.getElementById('btn-close-login');
  const loginCancel = document.getElementById('btn-cancel-login');
  const loginSubmit = document.getElementById('btn-do-login');

  const searchForm = document.getElementById('buscar-form');
  const searchSerie = document.getElementById('buscar_serie');
  const searchSerieError = document.getElementById('buscar_serie-error');
  const searchCorreo = document.getElementById('buscar_correo');
  const searchCorreoError = document.getElementById('buscar_correo-error');
  const searchSubmit = document.getElementById('btn-buscar');
  const searchResult = document.getElementById('search-result');
  const searchAlert = document.getElementById('search-alert');
  const searchAlertText = document.getElementById('search-alert-text');
  const searchCodigo = document.getElementById('search-codigo');
  const searchDron = document.getElementById('search-dron');
  const searchSerieOut = document.getElementById('search-serie');
  const searchFecha = document.getElementById('search-fecha');
  const searchEstado = document.getElementById('search-estado');

  const recordsCard = document.getElementById('records-card');
  const recordsPermission = document.getElementById('records-permission');
  const recordsToolbar = document.getElementById('records-toolbar');
  const recordsInput = document.getElementById('records_q');
  const recordsReset = document.getElementById('records-reset');
  const recordsBody = document.getElementById('records-body');
  const recordsEmpty = document.getElementById('records-empty');
  const recordsTableWrap = document.getElementById('records-table-wrap');
  const recordsTotal = document.getElementById('records-total');
  const recordsPage = document.getElementById('records-page');
  const recordsPages = document.getElementById('records-pages');
  const recordsPrev = document.getElementById('records-prev');
  const recordsNext = document.getElementById('records-next');

  const fieldNames = [
    'marca_dron',
    'modelo',
    'numero_serie',
    'nombre_propietario',
    'correo_contacto',
  ];

  const fields = {};
  fieldNames.forEach((name) => {
    fields[name] = {
      input: document.getElementById(name),
      error: document.getElementById(`${name}-error`),
    };
  });

  let csrfToken = '';
  let isSubmitting = false;
  let isRetrying = false;
  let currentRecord = null;
  let touched = new Set();
  let currentUser = null;
  let isLoginSubmitting = false;
  let isSearching = false;
  let isRecordsLoading = false;
  const recordsState = {
    q: '',
    page: 1,
    perPage: 10,
    totalPages: 1,
  };

  /* -------------------------------------------------------------------------
     Utilidades
     ---------------------------------------------------------------------- */

  const apiUrl = (path) => `${API_BASE}/${String(path).replace(/^\/+/, '')}`;

  const normalizeText = (value) =>
    String(value ?? '')
      .replace(/[\r\n\t]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

  const normalizeSerie = (value) =>
    String(value ?? '')
      .replace(/\s+/g, '')
      .toUpperCase();

  const emailPattern = /^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/;
  const namePattern = /^[\p{L}\p{M} .'-]+$/u;
  const brandPattern = /^[\p{L}\p{N} .,&+\-_/()'°]+$/u;

  const prefersReducedMotion = () =>
    window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const scrollToElement = (element) => {
    if (!element) return;
    element.scrollIntoView({
      behavior: prefersReducedMotion() ? 'auto' : 'smooth',
      block: 'start',
    });
  };

  const formatFecha = (value) => {
    const text = String(value ?? '').trim();
    if (!text) return '—';

    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return text;

    const meses = [
      'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
      'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];
    const anio = match[1];
    const mes = meses[Number(match[2]) - 1] || '';
    const dia = Number(match[3]);
    const hora = match[4] ? `${match[4]}:${match[5]} h` : '';

    return `${dia} de ${mes} de ${anio}${hora ? `, ${hora}` : ''}`;
  };

  const safeStorage = {
    get() {
      try {
        return window.sessionStorage.getItem(CONFIG.storageKey);
      } catch {
        return null;
      }
    },
    set(value) {
      try {
        window.sessionStorage.setItem(CONFIG.storageKey, value);
      } catch {
        /* sessionStorage puede estar bloqueado */
      }
    },
    remove() {
      try {
        window.sessionStorage.removeItem(CONFIG.storageKey);
      } catch {
        /* ignorar */
      }
    },
  };

  /* -------------------------------------------------------------------------
     Estado visual del formulario
     ---------------------------------------------------------------------- */

  const setLoading = (loading) => {
    isSubmitting = loading;
    submitButton.disabled = loading;
    submitButton.classList.toggle('is-loading', loading);
    form.setAttribute('aria-busy', loading ? 'true' : 'false');

    const label = submitButton.querySelector('.btn__label');
    if (label) {
      label.textContent = loading ? 'Enviando registro…' : 'Enviar registro';
    }
  };

  const setRetryLoading = (loading) => {
    isRetrying = loading;
    retryButton.disabled = loading;
    retryButton.classList.toggle('is-loading', loading);
    const label = retryButton.querySelector('.btn__label');
    if (label) {
      label.textContent = loading ? 'Reintentando…' : 'Reintentar envío';
    }
  };

  const showFormAlert = (message, title = 'No se pudo completar el registro') => {
    const strong = formAlert.querySelector('strong');
    if (strong) strong.textContent = title;
    formAlertText.textContent = message;
    formAlert.hidden = false;
  };

  const hideFormAlert = () => {
    formAlert.hidden = true;
    formAlertText.textContent = '';
  };

  const setFieldError = (name, message) => {
    const field = fields[name];
    if (!field || !field.input || !field.error) return;

    field.error.textContent = message || '';
    field.input.setAttribute('aria-invalid', message ? 'true' : 'false');
  };

  const clearFieldError = (name) => setFieldError(name, '');

  const clearAllErrors = () => {
    fieldNames.forEach(clearFieldError);
    hideFormAlert();
  };

  /* -------------------------------------------------------------------------
     Validación (espejo de la validación del backend)
     ---------------------------------------------------------------------- */

  const validateField = (name) => {
    const input = fields[name] && fields[name].input;
    if (!input) return '';

    const raw = input.value;
    const value = name === 'numero_serie' ? normalizeSerie(raw) : normalizeText(raw);

    switch (name) {
      case 'marca_dron': {
        if (!value) return 'La marca del dron es obligatoria.';
        if (value.length < 2 || value.length > 80) {
          return 'La marca debe tener entre 2 y 80 caracteres.';
        }
        if (!brandPattern.test(value)) {
          return 'La marca contiene caracteres no permitidos.';
        }
        return '';
      }

      case 'modelo': {
        if (!value) return 'El modelo del dron es obligatorio.';
        if (value.length < 2 || value.length > 80) {
          return 'El modelo debe tener entre 2 y 80 caracteres.';
        }
        if (!brandPattern.test(value)) {
          return 'El modelo contiene caracteres no permitidos.';
        }
        return '';
      }

      case 'numero_serie': {
        if (!value) return 'El número de serie es obligatorio.';
        if (value.length < 6 || value.length > 40) {
          return 'El número de serie debe tener entre 6 y 40 caracteres.';
        }
        if (!/^[A-Z0-9][A-Z0-9._-]{5,39}$/.test(value)) {
          return 'Use solo letras, números, puntos, guiones o guiones bajos. No se permiten espacios.';
        }
        return '';
      }

      case 'nombre_propietario': {
        if (!value) return 'El nombre completo del propietario es obligatorio.';
        if (value.length < 5 || value.length > 150) {
          return 'El nombre debe tener entre 5 y 150 caracteres.';
        }
        if (!namePattern.test(value)) {
          return 'El nombre solo puede contener letras, espacios, apóstrofos, puntos y guiones.';
        }
        if (value.split(/\s+/).filter(Boolean).length < 2) {
          return 'Ingrese el nombre completo (nombre y apellidos).';
        }
        return '';
      }

      case 'correo_contacto': {
        if (!value) return 'El correo electrónico es obligatorio.';
        if (value.length > 190) {
          return 'El correo electrónico no puede superar los 190 caracteres.';
        }
        if (!emailPattern.test(value)) {
          return 'Ingrese un correo electrónico válido (ejemplo: nombre@dominio.com).';
        }
        return '';
      }

      default:
        return '';
    }
  };

  const applyFieldVisibility = (name) => {
    const field = fields[name];
    if (!field || !field.input) return;
    const message = validateField(name);
    setFieldError(name, message);
    return message;
  };

  const validateAll = () => {
    const errors = [];
    fieldNames.forEach((name) => {
      const message = applyFieldVisibility(name);
      if (message) errors.push({ name, message });
    });
    return errors;
  };

  const focusFirstInvalid = (errors) => {
    if (!errors.length) return;
    const target = fields[errors[0].name] && fields[errors[0].name].input;
    if (target) {
      target.focus({ preventScroll: true });
      scrollToElement(target);
    }
  };

  const applyServerErrors = (errors) => {
    let firstField = '';
    if (!errors || typeof errors !== 'object') return firstField;

    Object.entries(errors).forEach(([name, message]) => {
      if (!fields[name]) return;
      if (!firstField) firstField = name;
      setFieldError(name, String(message));
    });

    return firstField;
  };

  const buildPayload = () => ({
    marca_dron: normalizeText(fields.marca_dron.input.value),
    modelo: normalizeText(fields.modelo.input.value),
    numero_serie: normalizeSerie(fields.numero_serie.input.value),
    nombre_propietario: normalizeText(fields.nombre_propietario.input.value),
    correo_contacto: normalizeText(fields.correo_contacto.input.value).toLowerCase(),
  });

  /* -------------------------------------------------------------------------
     Red
     ---------------------------------------------------------------------- */

  const fetchWithTimeout = async (url, options = {}, timeout = CONFIG.requestTimeout) => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeout);

    try {
      return await fetch(url, { ...options, signal: controller.signal });
    } finally {
      window.clearTimeout(timer);
    }
  };

  const readJson = async (response) => {
    const text = await response.text();
    if (!text) return {};
    try {
      return JSON.parse(text);
    } catch {
      return {};
    }
  };

  const getCsrfToken = async () => {
    if (csrfToken) return csrfToken;

    const response = await fetchWithTimeout(apiUrl('csrf.php'), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    }, 15000);

    const data = await readJson(response);
    if (!response.ok || !data || !data.csrf_token) {
      throw new Error('No se pudo iniciar la sesión segura del formulario.');
    }

    csrfToken = data.csrf_token;
    return csrfToken;
  };

  const initializeCsrf = async () => {
    try {
      await getCsrfToken();
    } catch (error) {
      // No se bloquea el formulario: al enviar se vuelve a intentar y se muestra
      // un mensaje claro si el backend no está disponible.
      csrfToken = '';
      console.warn('DGAC: no se pudo obtener el token CSRF.', error);
    }
  };

  /* -------------------------------------------------------------------------
     Acceso interno y consulta
     ---------------------------------------------------------------------- */

  const setDomFieldError = (input, errorElement, message) => {
    if (!input || !errorElement) return;
    errorElement.textContent = message || '';
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
  };

  const clearLoginErrors = () => {
    setDomFieldError(loginEmail, loginEmailError, '');
    setDomFieldError(loginPassword, loginPasswordError, '');
    loginAlert.hidden = true;
    if (loginAlertText) loginAlertText.textContent = '';
  };

  const setLoginLoading = (loading) => {
    isLoginSubmitting = loading;
    if (!loginSubmit) return;

    loginSubmit.disabled = loading;
    loginSubmit.classList.toggle('is-loading', loading);
    loginSubmit.setAttribute('aria-busy', loading ? 'true' : 'false');
    if (loginEmail) loginEmail.disabled = loading;
    if (loginPassword) loginPassword.disabled = loading;
  };

  const setLoginDialog = (open) => {
    if (!loginModal) return;
    loginModal.hidden = !open;
    if (loginButton) {
      loginButton.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        loginButton.removeAttribute('aria-haspopup');
      } else {
        loginButton.setAttribute('aria-haspopup', 'dialog');
      }
    }

    if (open) {
      document.body.classList.add('modal-open');
      clearLoginErrors();
      window.setTimeout(() => loginEmail?.focus({ preventScroll: true }), 0);
    } else {
      document.body.classList.remove('modal-open');
      loginButton?.focus({ preventScroll: true });
    }
  };

  const handleModalKeydown = (event) => {
    if (!loginModal || loginModal.hidden) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      setLoginDialog(false);
      return;
    }

    if (event.key !== 'Tab') return;

    const focusable = Array.from(loginModal.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    )).filter((element) => !element.disabled && element.offsetParent !== null);

    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  const showLoginAlert = (message) => {
    if (!loginAlert) return;
    loginAlert.hidden = false;
    if (loginAlertText) loginAlertText.textContent = message || 'Revise sus credenciales e intente nuevamente.';
  };

  const updateSessionUi = (user) => {
    currentUser = user || null;

    if (loginLabel) {
      loginLabel.textContent = currentUser ? 'Cerrar sesión' : 'Iniciar sesión';
    }
    if (loginButton) {
      const action = currentUser ? 'Cerrar sesión' : 'Iniciar sesión';
      loginButton.setAttribute('aria-label', action);
      loginButton.setAttribute('aria-expanded', 'false');
      if (currentUser) {
        loginButton.removeAttribute('aria-haspopup');
      } else {
        loginButton.setAttribute('aria-haspopup', 'dialog');
      }
    }

    if (sessionPanel) sessionPanel.hidden = !currentUser;
    if (sessionUserName) sessionUserName.textContent = currentUser?.nombre || '—';
    if (sessionUserRole) sessionUserRole.textContent = currentUser?.rol_label || '—';
    if (sessionUserEmail) sessionUserEmail.textContent = currentUser?.correo || '—';

    if (recordsCard) {
      recordsCard.hidden = !currentUser;
    }

    if (recordsPermission) {
      recordsPermission.textContent = currentUser?.puede_descargar
        ? 'Puede descargar comprobantes en PDF.'
        : 'Acceso de solo lectura: su rol no permite descargar comprobantes.';
      recordsPermission.classList.toggle('is-admin', !!currentUser?.puede_descargar);
    }
  };

  const fetchSession = async () => {
    try {
      const response = await fetchWithTimeout(apiUrl('sesion.php'), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      }, 15000);
      const data = await readJson(response);
      const user = data?.data?.user || null;
      updateSessionUi(user);
      return user;
    } catch (error) {
      console.warn('DGAC: no se pudo consultar la sesión.', error);
      updateSessionUi(null);
      return null;
    }
  };

  const handleLogin = async (event) => {
    event?.preventDefault?.();
    if (isLoginSubmitting) return;

    clearLoginErrors();
    const email = normalizeText(loginEmail?.value);
    const password = String(loginPassword?.value || '');
    let invalid = false;

    if (!email || !emailPattern.test(email)) {
      setDomFieldError(loginEmail, loginEmailError, 'Ingrese un correo electrónico válido.');
      invalid = true;
    }
    if (!password) {
      setDomFieldError(loginPassword, loginPasswordError, 'Ingrese la contraseña.');
      invalid = true;
    }
    if (invalid) {
      (loginEmailError.textContent ? loginEmail : loginPassword)?.focus();
      return;
    }

    setLoginLoading(true);

    try {
      const token = await getCsrfToken();
      const response = await fetchWithTimeout(apiUrl('login.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify({ correo: email, password, csrf_token: token }),
      });
      const data = await readJson(response);

      if (response.status === 419) {
        csrfToken = '';
        showLoginAlert('La sesión expiró. Recargue la página e intente nuevamente.');
        return;
      }

      if (!response.ok || !data.ok || !data?.data?.user) {
        showLoginAlert(data?.mensaje || data?.errores?.correo || data?.errores?.password || 'Correo o contraseña incorrectos.');
        return;
      }

      updateSessionUi(data.data.user);
      if (loginPassword) loginPassword.value = '';
      setLoginDialog(false);
      recordsState.page = 1;
      await loadRecords();
    } catch (error) {
      console.warn('DGAC: no se pudo iniciar sesión.', error);
      showLoginAlert('No se pudo conectar con el servidor. Intente nuevamente.');
    } finally {
      setLoginLoading(false);
    }
  };

  const handleLogout = async () => {
    try {
      const token = await getCsrfToken();
      await fetchWithTimeout(apiUrl('logout.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify({ csrf_token: token }),
      }, 15000);
    } catch (error) {
      console.warn('DGAC: no se pudo cerrar la sesión en el servidor.', error);
    } finally {
      updateSessionUi(null);
      if (recordsInput) recordsInput.value = '';
    }
  };

  const handleLoginButton = () => {
    if (currentUser) {
      handleLogout();
      return;
    }
    setLoginDialog(true);
  };

  const clearSearchFeedback = () => {
    setDomFieldError(searchSerie, searchSerieError, '');
    setDomFieldError(searchCorreo, searchCorreoError, '');
    if (searchAlert) searchAlert.hidden = true;
    if (searchAlertText) searchAlertText.textContent = '';
  };

  const setSearchLoading = (loading) => {
    isSearching = loading;
    if (!searchSubmit) return;

    searchSubmit.disabled = loading;
    searchSubmit.classList.toggle('is-loading', loading);
    searchSubmit.setAttribute('aria-busy', loading ? 'true' : 'false');
    if (searchSerie) searchSerie.disabled = loading;
    if (searchCorreo) searchCorreo.disabled = loading;
  };

  const renderPublicSearchResult = (record) => {
    const status = statusInfo(record);
    if (searchCodigo) searchCodigo.textContent = record.codigo || '—';
    if (searchDron) searchDron.textContent = [record.marca_dron, record.modelo].filter(Boolean).join(' · ') || '—';
    if (searchSerieOut) searchSerieOut.textContent = record.numero_serie || '—';
    if (searchFecha) searchFecha.textContent = formatFecha(record.fecha_registro);
    if (searchEstado) {
      searchEstado.textContent = status.label;
      searchEstado.className = `status ${status.className}`;
    }
    if (searchResult) {
      searchResult.hidden = false;
      searchResult.focus?.({ preventScroll: true });
    }
  };

  const handlePublicSearch = async (event) => {
    event?.preventDefault?.();
    if (isSearching) return;

    clearSearchFeedback();
    if (searchResult) searchResult.hidden = true;

    const serie = normalizeSerie(searchSerie?.value);
    const correo = String(searchCorreo?.value || '').trim().toLowerCase();
    let invalid = false;

    if (!serie || !/^[A-Z0-9][A-Z0-9._-]{5,39}$/.test(serie)) {
      setDomFieldError(searchSerie, searchSerieError, 'Ingrese un número de serie válido.');
      invalid = true;
    }
    if (!correo || !emailPattern.test(correo)) {
      setDomFieldError(searchCorreo, searchCorreoError, 'Ingrese un correo electrónico válido.');
      invalid = true;
    }
    if (invalid) {
      (searchSerieError.textContent ? searchSerie : searchCorreo)?.focus();
      return;
    }

    setSearchLoading(true);

    try {
      const token = await getCsrfToken();
      const response = await fetchWithTimeout(apiUrl('buscar.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify({ numero_serie: serie, correo_contacto: correo, csrf_token: token }),
      });
      const data = await readJson(response);

      if (response.status === 419) {
        csrfToken = '';
        if (searchAlert) searchAlert.hidden = false;
        if (searchAlertText) searchAlertText.textContent = 'La sesión expiró. Recargue la página e intente nuevamente.';
        return;
      }

      if (response.status === 422 && data?.errores) {
        setDomFieldError(searchSerie, searchSerieError, data.errores.numero_serie || '');
        setDomFieldError(searchCorreo, searchCorreoError, data.errores.correo_contacto || '');
        return;
      }

      if (response.status === 404 || !response.ok || !data?.data) {
        if (searchAlert) searchAlert.hidden = false;
        if (searchAlertText) searchAlertText.textContent = data?.mensaje || 'No se encontró un registro con esos datos.';
        return;
      }

      renderPublicSearchResult(data.data);
    } catch (error) {
      console.warn('DGAC: no se pudo buscar el registro.', error);
      if (searchAlert) searchAlert.hidden = false;
      if (searchAlertText) searchAlertText.textContent = 'No se pudo conectar con el servidor. Intente nuevamente.';
    } finally {
      setSearchLoading(false);
    }
  };

  const clearRecords = () => {
    if (recordsBody) recordsBody.textContent = '';
    if (recordsTotal) recordsTotal.textContent = '0';
    if (recordsPage) recordsPage.textContent = '1';
    if (recordsPages) recordsPages.textContent = '1';
    if (recordsPrev) recordsPrev.disabled = true;
    if (recordsNext) recordsNext.disabled = true;
    if (recordsEmpty) recordsEmpty.hidden = true;
    if (recordsTableWrap) recordsTableWrap.hidden = false;
  };

  const renderRecords = (items, pagination, canDownload) => {
    if (!recordsBody) return;
    recordsBody.textContent = '';

    for (const item of items) {
      const row = document.createElement('tr');

      const addCell = (text) => {
        const cell = document.createElement('td');
        cell.textContent = text ?? '—';
        return cell;
      };

      const addStatusCell = () => {
        const cell = document.createElement('td');
        const status = statusInfo(item);
        const label = document.createElement('span');
        label.className = `status ${status.className}`;
        label.textContent = status.label;
        cell.appendChild(label);
        return cell;
      };

      const addDownloadCell = () => {
        const cell = document.createElement('td');

        if (canDownload) {
          const link = document.createElement('a');
          link.className = 'records-download';
          link.href = apiUrl(`pdf.php?id=${encodeURIComponent(item.id)}`);
          link.setAttribute('download', '');
          link.innerHTML = '<img src="assets/icons/pdf.svg" alt="" width="18" height="18" aria-hidden="true"> PDF';
          cell.appendChild(link);
        } else {
          const denied = document.createElement('span');
          denied.className = 'records-no-download';
          denied.textContent = 'Sin permiso';
          cell.appendChild(denied);
        }

        return cell;
      };

      const code = document.createElement('code');
      code.textContent = item.codigo || '—';
      const codeCell = document.createElement('td');
      codeCell.appendChild(code);

      row.appendChild(codeCell);
      row.appendChild(addCell([item.marca_dron, item.modelo].filter(Boolean).join(' · ') || '—'));
      row.appendChild(addCell(item.numero_serie));
      row.appendChild(addCell(item.nombre_propietario));
      row.appendChild(addCell(item.correo_contacto));
      row.appendChild(addCell(formatFecha(item.fecha_registro)));
      row.appendChild(addStatusCell());
      row.appendChild(addDownloadCell());
      recordsBody.appendChild(row);
    }

    const page = Math.max(1, Number(pagination?.page || 1));
    const totalPages = Math.max(0, Number(pagination?.total_pages || 0));
    const total = Math.max(0, Number(pagination?.total || 0));
    recordsState.page = page;
    recordsState.totalPages = totalPages;

    if (recordsTotal) recordsTotal.textContent = String(total);
    if (recordsPage) recordsPage.textContent = String(page);
    if (recordsPages) recordsPages.textContent = String(Math.max(1, totalPages));
    if (recordsEmpty) recordsEmpty.hidden = total !== 0;
    if (recordsTableWrap) recordsTableWrap.hidden = total === 0;
    if (recordsPrev) recordsPrev.disabled = page <= 1;
    if (recordsNext) recordsNext.disabled = totalPages === 0 || page >= totalPages;
  };

  const loadRecords = async () => {
    if (!currentUser || !recordsCard || recordsCard.hidden || isRecordsLoading) return;

    isRecordsLoading = true;
    if (recordsPrev) recordsPrev.disabled = true;
    if (recordsNext) recordsNext.disabled = true;

    try {
      const params = new URLSearchParams({
        page: String(recordsState.page),
        per_page: String(recordsState.perPage),
      });
      if (recordsState.q) params.set('q', recordsState.q);

      const response = await fetchWithTimeout(`${apiUrl('drones.php')}?${params.toString()}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      }, 30000);
      const data = await readJson(response);

      if (response.status === 401 || response.status === 403) {
        updateSessionUi(null);
        clearRecords();
        return;
      }

      if (!response.ok || !data?.data) {
        throw new Error(data?.mensaje || 'No se pudo cargar el listado.');
      }

      renderRecords(
        Array.isArray(data.data.items) ? data.data.items : [],
        data.data.pagination || {},
        !!data.data.puede_descargar
      );
    } catch (error) {
      console.warn('DGAC: no se pudo cargar el listado interno.', error);
      if (recordsEmpty) {
        recordsEmpty.hidden = false;
        const title = recordsEmpty.querySelector('strong');
        const text = recordsEmpty.querySelector('p');
        if (title) title.textContent = 'No se pudo cargar el listado';
        if (text) text.textContent = 'Verifique la conexión o que el usuario siga activo.';
      }
      if (recordsTableWrap) recordsTableWrap.hidden = true;
    } finally {
      isRecordsLoading = false;
    }
  };

  const resetRecords = () => {
    recordsState.q = '';
    recordsState.page = 1;
    if (recordsInput) recordsInput.value = '';
    loadRecords();
  };

  /* -------------------------------------------------------------------------
     Confirmación
     ---------------------------------------------------------------------- */

  const statusInfo = (record) => {
    if (record.estado_registro === 'CORREO_ENVIADO' || record.correo_enviado) {
      return { label: 'Correo enviado', className: 'status--ok' };
    }
    if (record.estado_registro === 'CORREO_FALLIDO') {
      return { label: 'Envío pendiente', className: 'status--pending' };
    }
    return { label: 'Registrado', className: 'status--neutral' };
  };

  const setPdfLink = (record) => {
    if (record.puede_descargar) {
      if (record.pdf_url) {
        pdfButton.href = record.pdf_url;
      } else if (record.id) {
        pdfButton.href = apiUrl(`pdf.php?id=${encodeURIComponent(record.id)}`);
      } else {
        pdfButton.removeAttribute('href');
      }
      pdfButton.hidden = false;
      return;
    }

    pdfButton.hidden = true;
    pdfButton.removeAttribute('href');
  };

  const renderRecord = (record, message = '') => {
    if (!record) return;

    currentRecord = record;
    const status = statusInfo(record);

    successCodigo.textContent = record.codigo || '—';
    successFecha.textContent = formatFecha(record.fecha_registro);
    successDron.textContent = [record.marca_dron, record.modelo].filter(Boolean).join(' · ') || '—';
    successSerie.textContent = record.numero_serie || '—';
    successPropietario.textContent = record.nombre_propietario || '—';
    successCorreo.textContent = record.correo_contacto || '—';
    successEstado.textContent = status.label;
    successEstado.className = `status ${status.className}`;

    successTitle.textContent = record.correo_enviado
      ? 'Su dron fue registrado'
      : 'Registro guardado, envío pendiente';

    successMessage.textContent = message || (record.correo_enviado
      ? 'El registro se completó correctamente y el comprobante fue enviado a su correo electrónico.'
      : 'El registro se guardó correctamente. El comprobante aún no se ha enviado por correo.');

    setPdfLink(record);

    const needsRetry = !record.correo_enviado;
    successWarning.hidden = !needsRetry;
    successInfo.hidden = !!needsRetry;

    if (needsRetry) {
      successWarningText.textContent = record.reintento_disponible
        ? 'El registro quedó guardado correctamente. Puede reintentar el envío del comprobante.'
        : 'El registro quedó guardado correctamente. Se alcanzó el máximo de reintentos automáticos; contacte a la DGAC para completar el envío.';
    } else {
      successInfoText.textContent = record.correo_contacto
        ? `Revise la bandeja de entrada de ${record.correo_contacto} y la carpeta de correo no deseado.`
        : 'Revise su bandeja de entrada y la carpeta de correo no deseado.';
    }

    const canRetry = needsRetry && !!record.reintento_disponible;
    retryButton.hidden = !canRetry;
    retryButton.disabled = false;

    if (needsRetry && !record.reintento_disponible) {
      retryNote.hidden = false;
      retryNote.textContent = 'Por seguridad, el reintento automático está limitado. Comuníquese con la DGAC indicando el código único del registro.';
    } else if (needsRetry) {
      retryNote.hidden = false;
      retryNote.textContent = 'El registro permanece guardado aunque el correo no se haya enviado.';
    } else {
      retryNote.hidden = true;
      retryNote.textContent = '';
    }

    panelForm.hidden = true;
    panelSuccess.hidden = false;
    scrollToElement(panelSuccess);
    window.setTimeout(() => panelSuccess.focus({ preventScroll: true }), 250);

    if (record.token_comprobante) {
      safeStorage.set(JSON.stringify({ token: record.token_comprobante }));
    }
  };

  const showNewForm = () => {
    safeStorage.remove();
    currentRecord = null;
    form.reset();
    clearAllErrors();
    touched = new Set();
    panelSuccess.hidden = true;
    panelForm.hidden = false;
    retryButton.hidden = true;
    successWarning.hidden = true;
    successInfo.hidden = true;
    retryNote.hidden = true;
    scrollToElement(panelForm);
    window.setTimeout(() => fields.marca_dron.input.focus({ preventScroll: true }), 200);
  };

  /* -------------------------------------------------------------------------
     Envío del registro
     ---------------------------------------------------------------------- */

  const handleSubmit = async (event) => {
    event.preventDefault();

    if (isSubmitting) return;

    hideFormAlert();
    const errors = validateAll();

    if (errors.length) {
      showFormAlert(
        'Revise los campos marcados. Todos los datos son obligatorios y deben tener un formato válido.',
        'Faltan datos por corregir'
      );
      focusFirstInvalid(errors);
      return;
    }

    setLoading(true);

    try {
      const token = await getCsrfToken();

      const response = await fetchWithTimeout(apiUrl('registro.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify(buildPayload()),
      });

      const data = await readJson(response);

      if (response.status === 419) {
        csrfToken = '';
        showFormAlert(
          'La sesión del formulario expiró. Recargue la página o vuelva a enviar el formulario.',
          'Sesión expirada'
        );
        return;
      }

      if (!response.ok || !data.ok) {
        const firstField = applyServerErrors(data.errores);
        const message = data.mensaje || 'No fue posible completar el registro. Intente nuevamente.';

        showFormAlert(message);
        if (firstField && fields[firstField]) {
          fields[firstField].input.focus({ preventScroll: true });
          scrollToElement(fields[firstField].input);
        }
        return;
      }

      renderRecord(data.data, data.mensaje);
    } catch (error) {
      const message = error && error.name === 'AbortError'
        ? 'La solicitud tardó demasiado. Verifique su conexión e intente nuevamente.'
        : 'No se pudo conectar con el servicio de registro. Verifique su conexión e intente nuevamente.';
      showFormAlert(message, 'Error de conexión');
    } finally {
      setLoading(false);
    }
  };

  /* -------------------------------------------------------------------------
     Reintento de correo
     ---------------------------------------------------------------------- */

  const handleRetry = async () => {
    if (isRetrying || !currentRecord || !currentRecord.token_comprobante) return;

    setRetryLoading(true);
    successWarning.hidden = false;
    successWarningText.textContent = 'Reintentando el envío del comprobante…';

    try {
      const token = await getCsrfToken();

      const response = await fetchWithTimeout(apiUrl('reintentar.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify({
          token: currentRecord.token_comprobante,
          csrf_token: token,
        }),
      });

      const data = await readJson(response);

      if (response.status === 419) {
        csrfToken = '';
        successWarning.hidden = false;
        successWarningText.textContent = 'La sesión expiró. Recargue la página para reintentar el envío.';
        return;
      }

      if (!response.ok || !data.ok) {
        successWarning.hidden = false;
        successWarningText.textContent = data.mensaje || 'No fue posible reenviar el comprobante. Intente nuevamente.';
        return;
      }

      renderRecord(data.data, data.mensaje);
    } catch (error) {
      successWarning.hidden = false;
      successWarningText.textContent = error && error.name === 'AbortError'
        ? 'La solicitud tardó demasiado. Intente nuevamente.'
        : 'No se pudo conectar con el servicio de correo. Intente nuevamente.';
    } finally {
      setRetryLoading(false);
    }
  };

  /* -------------------------------------------------------------------------
     Restauración de la última confirmación
     ---------------------------------------------------------------------- */

  const restoreLastRecord = async () => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('nuevo') === '1') {
      safeStorage.remove();
      return;
    }

    const stored = safeStorage.get();
    if (!stored) return;

    let token = '';
    try {
      token = JSON.parse(stored).token || '';
    } catch {
      safeStorage.remove();
      return;
    }

    if (!token) {
      safeStorage.remove();
      return;
    }

    try {
      const response = await fetchWithTimeout(
        apiUrl(`estado.php?token=${encodeURIComponent(token)}`),
        { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' },
        15000
      );
      const data = await readJson(response);

      if (response.ok && data.ok && data.data) {
        renderRecord(data.data);
      } else if (response.status === 404) {
        safeStorage.remove();
      }
    } catch {
      // Sin conexión: se conserva el token para el próximo intento.
    }
  };

  /* -------------------------------------------------------------------------
     Eventos
     ---------------------------------------------------------------------- */

  const bindEvents = () => {
    form.addEventListener('submit', handleSubmit);

    fieldNames.forEach((name) => {
      const field = fields[name];
      if (!field.input) return;

      field.input.addEventListener('blur', () => {
        touched.add(name);
        applyFieldVisibility(name);
      });

      field.input.addEventListener('input', () => {
        if (touched.has(name) || field.error.textContent) {
          applyFieldVisibility(name);
        }
      });
    });

    loginButton?.addEventListener('click', handleLoginButton);
    loginClose?.addEventListener('click', () => setLoginDialog(false));
    loginCancel?.addEventListener('click', () => setLoginDialog(false));
    loginForm?.addEventListener('submit', handleLogin);
    loginModal?.addEventListener('click', (event) => {
      if (event.target === loginModal) setLoginDialog(false);
    });
    document.addEventListener('keydown', handleModalKeydown);

    searchForm?.addEventListener('submit', handlePublicSearch);
    searchSerie?.addEventListener('input', () => { setDomFieldError(searchSerie, searchSerieError, ''); if (searchAlert) searchAlert.hidden = true; });
    searchCorreo?.addEventListener('input', () => { setDomFieldError(searchCorreo, searchCorreoError, ''); if (searchAlert) searchAlert.hidden = true; });

    recordsToolbar?.addEventListener('submit', (event) => {
      event.preventDefault();
      recordsState.q = normalizeText(recordsInput?.value);
      recordsState.page = 1;
      loadRecords();
    });
    recordsReset?.addEventListener('click', resetRecords);
    recordsPrev?.addEventListener('click', () => {
      if (recordsState.page > 1) {
        recordsState.page -= 1;
        loadRecords();
      }
    });
    recordsNext?.addEventListener('click', () => {
      if (recordsState.page < recordsState.totalPages) {
        recordsState.page += 1;
        loadRecords();
      }
    });

    retryButton.addEventListener('click', handleRetry);
    newButton.addEventListener('click', showNewForm);

    // Limpia el aviso general cuando el usuario empieza a corregir.
    form.addEventListener('input', () => {
      if (!formAlert.hidden) hideFormAlert();
    });
  };

  const setYear = () => {
    const year = document.getElementById('anio-actual');
    if (year) year.textContent = String(new Date().getFullYear());
  };

  const init = () => {
    setYear();
    bindEvents();
    initializeCsrf();
    fetchSession().then((user) => {
      if (user) loadRecords();
    });
    restoreLastRecord();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();

