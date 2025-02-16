(async function() {
  
    // НАСТРОЙКИ 
    const longMode = 0;         // 1 – включен режим long
    const CHUNK_SIZE = 7;      // оптимальный размер чанка можно подобрать экспериментально
    
    // НАСТРОЙКИ - END
  
  // 0. Вставляем CSS-стили для прелоадера
  const styleBlock = `
  <style>
    #translation-blur-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255,255,255,0.6);
      backdrop-filter: blur(5px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      opacity: 1;
      transition: opacity 0.5s;
    }
    .overlay-hidden {
      opacity: 0;
      pointer-events: none;
    }
    .spinner-circle {
      width: 50px;
      height: 50px;
      border: 5px solid #ccc;
      border-top: 5px solid #333;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    @keyframes spin {
      100% { transform: rotate(360deg); }
    }
  </style>
  `;
  document.head.insertAdjacentHTML('beforeend', styleBlock);

  // 0.1. Вставляем прелоадер
  const preloaderHTML = `
    <div id="translation-blur-overlay">
      <div class="spinner-circle"></div>
    </div>
  `;
  document.body.insertAdjacentHTML('afterbegin', preloaderHTML);
  document.body.classList.add('invisible');

  // 1. Определяем язык страницы и браузера
  function getPageLanguage() {
    const dataCountry = document.documentElement.getAttribute('data-country');
    if (dataCountry) return dataCountry.toLowerCase();
    const htmlLang = document.documentElement.lang;
    if (htmlLang) return htmlLang.split('-')[0].toLowerCase();
    return 'en';
  }
  const pageLang = getPageLanguage();
  let userLang = (navigator.language || navigator.userLanguage || 'en')
                 .split('-')[0].toLowerCase();

  // 2. Функция скрытия прелоадера
  function hideBlurOverlay() {
    const overlay = document.getElementById('translation-blur-overlay');
    if (!overlay) return;
    overlay.classList.add('overlay-hidden');
    setTimeout(() => {
      overlay.style.display = 'none';
      document.body.classList.remove('invisible');
    }, 500);
  }

  // Если языки совпадают – перевод не нужен
  if (pageLang === userLang) {
    console.log("Языки совпадают:", pageLang, "=> без перевода");
    hideBlurOverlay();
    return;
  }
  console.log("Перевод с", pageLang, "на", userLang);

  // 3. Собираем все текстовые узлы (без тех, что внутри .no_translate)
  const linesData = [];
  let currentId = 0;
  function walkDOM(node) {
    if (node.nodeType === Node.ELEMENT_NODE && node.classList.contains('no_translate')) return;
    if (node.nodeType === Node.TEXT_NODE) {
      const txt = node.nodeValue.trim();
      if (txt.length > 0) {
        linesData.push({ id: currentId++, text: txt, node: node });
      }
    }
    let child = node.firstChild;
    while (child) {
      walkDOM(child);
      child = child.nextSibling;
    }
  }
  walkDOM(document.body);

  // 4. Формируем полный JSON лендинга
  const payload = {
    lines: linesData.map(line => ({ id: line.id, text: line.text })),
    sourceLanguage: pageLang,
    targetLanguage: userLang
  };

  // 5. Режим long: разбиваем текст на чанки и отправляем запросы параллельно
  const cacheKey = userLang;  // для кэширования (например, ru.json)
  const totalLines = linesData.length;

  async function sendRequest(payload) {
    const TRANSLATE_ENDPOINT = 'translate.php';
    const response = await fetch(TRANSLATE_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    if (!response.ok) {
      throw new Error(`Сервер вернул код ${response.status}`);
    }
    return await response.json();
  }

  try {
    let translatedLines = [];
    if (longMode === 1) {
      // Функция разбиения массива на чанки
      function chunkArray(array, size) {
        const result = [];
        for (let i = 0; i < array.length; i += size) {
          result.push(array.slice(i, i + size));
        }
        return result;
      }
      const chunks = chunkArray(linesData, CHUNK_SIZE);
      console.log(`Разбили текст на ${chunks.length} частей`);

      // Формируем массив промисов — для каждого чанка свой payload
      const requests = chunks.map(chunk => {
        const chunkPayload = {
          lines: chunk.map(line => ({ id: line.id, text: line.text })),
          sourceLanguage: pageLang,
          targetLanguage: userLang,
          cacheKey: cacheKey,
          totalLines: totalLines
        };
        return sendRequest(chunkPayload);
      });

      // Ожидаем завершения всех запросов параллельно
      const responses = await Promise.all(requests);

      // Логика объединения: предполагаем, что сервер уже склеил перевод,
      // и последний ответ содержит полный перевод. Если сервер объединяет чанки,
      // можно взять ответ последнего запроса.
      translatedLines = responses[responses.length - 1].lines;
    } else {
      const data = await sendRequest(payload);
      translatedLines = data.lines;
    }

    console.log("Перевод получен. Подмена текста...");
    // 6. Подмена переведённого текста в DOM
    translatedLines.forEach(({ id, text }) => {
      const line = linesData.find(line => line.id === id);
      if (line && line.node) {
        line.node.nodeValue = text;
      }
    });
    console.log("Подмена завершена.");
  } catch (err) {
    console.error("Ошибка перевода:", err);
  } finally {
    // 7. Скрываем прелоадер
    hideBlurOverlay();
  }
})();
