<?php

declare(strict_types=1);

/**
 * The list help pages, in Spanish. See lang/nl/help_lists.php for the shape,
 * the [words](path) link syntax, the "1. " steps, and why this is not in
 * site.php.
 */
return [
    'index' => [
        'title' => 'Cómo funcionan las listas',
        'seo_title' => 'Cómo funcionan las listas',
        'seo_description' => 'Todo lo que permite una lista: guardar, compartir, regalar entre varios, Amigo invisible, amigos y recordatorios. Paso a paso, con imágenes.',
        'intro' => 'Una lista guarda lo que encuentras aquí, para ti o para otra persona. Abajo, lo que puedes hacer con ella y cómo, tema por tema, con imágenes. Empieza por el primero si nunca has guardado nada.',
        'back' => 'Todos los temas',
        'next' => 'Siguiente',
        'cta_search' => 'Buscar algo que guardar',
        'cta_lists' => 'Ir a mis listas',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Guardar y crear una lista',
            'blurb' => 'Encontrar algo, guardarlo, abrir tus listas, y crear una lista en tres pasos.',
            'seo_description' => 'Guarda todo lo que encuentres aquí en una lista de deseos y crea una en tres pasos. Con imágenes.',
            'intro' => 'No hace falta crear una lista antes. Al guardar se te ofrece, y la portada tiene un botón que crea una en tres pasos.',
            'numbered' => true,
            'sections' => [
                [
                    'title' => 'Encuentra algo que quieras guardar',
                    'body' => '[Busca](search), o recorre una [Cove](cove). Cada ficha de producto lleva un marcador sobre su foto.',
                    'shot' => 'find',
                    'alt' => 'Dos fichas de producto, cada una con un botón de marcador sobre su foto.',
                ],
                [
                    'title' => 'Guárdalo y elige una lista',
                    'body' => 'Toca el marcador y ya está en tu lista. Toca otra vez para elegir otra lista o empezar una nueva. En un ordenador, una flecha pequeña junto al marcador abre ese panel directamente.',
                    'shot' => 'choose',
                    'alt' => 'El panel abierto junto a un producto, con las listas donde guardar y la opción de empezar una nueva.',
                ],
                [
                    'title' => 'Abre tus listas',
                    'body' => 'Todo lo que guardaste está en [Mis listas](lists). Cada lista muestra qué contiene, si es privada y para quién es. Cuando baja un precio, la ficha lo dice.',
                    'shot' => 'lists',
                    'alt' => 'La página Mis listas, con dos listas y el botón para crear una.',
                ],
                [
                    'title' => 'Crear una lista en tres pasos',
                    'body' => "1. Toca «Nueva lista» en [Mis listas](lists), o «Crear una lista nueva» en la portada.\n2. Elige para quién es: «Para mi», «Para otra persona» o «Entre varios, para alguien». Toca «Siguiente».\n3. Ponle un nombre y una ocasión a la lista. Toca «Siguiente».\n4. Elige «Privada (o compartir después)» o «Compartir con un enlace», y toca «Crear lista».\n\nO sáltate esto: al guardar, toca el marcador y elige ahí una lista nueva. Lo que estabas guardando entra en ella al momento.\n\nUna elección queda fija después: para quién es. Todo lo demás se puede cambiar. Lo que permite cada tipo está en [Lista de deseos, lista de regalos o regalo en grupo](lists-help/kinds).",
                    'shot' => 'wizard',
                    'alt' => 'El primer paso de una lista nueva, con las tres opciones de para quién es.',
                ],
                [
                    'title' => 'No hace falta iniciar sesión para empezar',
                    'body' => 'Los tres pasos funcionan sin cuenta. Al final inicias sesión y la lista está ahí. Lo que rellenaste se guarda un día, así que puedes ausentarte.',
                ],
            ],
        ],

        'kinds' => [
            'title' => 'Lista de deseos, lista de regalos o regalo en grupo',
            'blurb' => 'La única elección que queda fija, y lo que permite cada tipo de lista.',
            'seo_description' => 'Tres tipos de lista: una lista de deseos para ti, una lista de regalos para otra persona, o un regalo en grupo. Qué permite cada una y qué queda fijo.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Una elección queda fija',
                    'body' => 'El primer paso de una [lista nueva](lists-help/saving) pregunta para quién es. Eso decide lo que la lista permite, y es lo único que no se cambia después. El nombre, la ocasión y quién la ve se pueden cambiar siempre.',
                ],
                [
                    'title' => 'Para mí: una lista de deseos',
                    'body' => 'Lo que te gustaría. [Compártela](lists-help/sharing) y los demás pueden marcar lo que compran, y tú no ves ni qué ni quién. Así se mantiene la sorpresa. Si prefieres saberlo, actívalo en esa lista.',
                ],
                [
                    'title' => 'Para otra persona: una lista de regalos',
                    'body' => 'Ideas para alguien que nunca abre la lista. Quienes la reciben marcan lo que [compran](lists-help/claiming), para que nadie compre dos veces. Tú sí lo ves, porque también regalas.',
                ],
                [
                    'title' => 'Entre varios, para alguien: un regalo en grupo',
                    'body' => 'Un regalo, varios que regalan. Cualquiera con el enlace puede añadir ideas, votarlas y decir con cuánto contribuye. Aquí no se mueve dinero; eso lo arregláis entre vosotros. Más en [Regalar entre varios](lists-help/group).',
                ],
                [
                    'title' => 'Una ocasión y una fecha',
                    'body' => 'Cualquier lista puede llevar una ocasión: cumpleaños, Navidad, boda, nacimiento, y diez más. Para un cumpleaños, Navidad y San Valentín, la fecha se rellena sola. Con una fecha, recibes un [recordatorio](lists-help/friends) a tiempo.',
                ],
            ],
        ],

        'items' => [
            'title' => 'Qué va en una lista',
            'blurb' => 'Productos de aquí, artículos propios con un enlace, copiar, editar, y el precio que baja.',
            'seo_description' => 'Guardar productos, añadir artículos propios con enlace y precio, copiar a otra lista, y ver cuándo baja un precio.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'El marcador',
                    'body' => 'En cada ficha de producto, en la [búsqueda](search) y en cada [Cove](cove). Un toque guarda en la última lista donde guardaste, si no en tu lista por defecto. Otro toque abre el panel: ahí eliges otra lista, lo mueves, o lo quitas. En un ordenador, una flecha pequeña junto al marcador abre el panel directamente.',
                ],
                [
                    'title' => 'Añadir desde la lista',
                    'body' => "1. Abre tu lista en [Mis listas](lists).\n2. Toca «+ Añadir un producto».\n3. Escribe lo que buscas y pulsa Intro, o toca el icono de escanear y apunta con la cámara al código de barras.\n4. Toca el producto en los resultados. Entra directamente en tu lista.",
                    'shot' => 'add',
                    'alt' => 'El campo de búsqueda al principio de una lista para añadir un producto, con debajo el enlace para añadirlo tú mismo.',
                ],
                [
                    'title' => 'Algo que no está en este sitio',
                    'body' => "1. Toca «+ Añadir un producto».\n2. Bajo el campo de búsqueda, elige «Añádelo tú mismo».\n3. Escribe qué es. Puede llevar un enlace, un precio y una nota como «talla M, en azul».\n4. Guárdalo.\n\nTus propios artículos se pueden cambiar después con «Editar». Los del catálogo no: su título y precio vienen de la tienda.",
                ],
                [
                    'title' => 'Copiar, no mover',
                    'body' => 'Cada artículo tiene «Copiar a otra lista». En la [lista compartida](lists-help/claiming) de otra persona es «Añadir a mi lista». El original se queda; la nota y el precio van con él, quien lo compra no.',
                ],
                [
                    'title' => 'Cuando baja el precio',
                    'body' => 'Un producto guardado recuerda el precio de ese momento. Si baja, la ficha muestra el nuevo precio con el antiguo tachado. No hay nada que configurar. Más en [Vigilar precios y stock](lists-help/alerts).',
                ],
                [
                    'title' => 'Quitar',
                    'body' => 'Toca la cruz del artículo y confirma. Solo quien gestiona la lista puede quitar un artículo. Lo más nuevo está arriba.',
                ],
            ],
        ],

        'sharing' => [
            'title' => 'Compartir una lista',
            'blurb' => 'Con un enlace o con amigos por su nombre, quién ve qué, y cómo dejar de compartir.',
            'seo_description' => 'Compartir una lista de deseos con un enlace o con amigos, decidir quién puede añadir y quién ve lo comprado, y dejar de compartir.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Privada hasta que la compartes',
                    'body' => 'Una lista nueva solo la ves tú. Nada la comparte a escondidas: ni una ocasión, ni un amigo, ni un quiz. La compartes tú, con «Compartir» en la lista.',
                ],
                [
                    'title' => 'Con un enlace',
                    'body' => "1. Abre tu lista y toca «Compartir».\n2. Ponla en «Compartir con un enlace» si aún es privada.\n3. Toca «Copiar enlace» y pégalo en un mensaje. O toca «Copiar mensaje y enlace» para un mensaje ya escrito, o «Compartir» para elegir WhatsApp, Telegram, correo u otra aplicación.\n\nCualquiera con el enlace ve la lista. «Dejar de compartir» invalida todos los enlaces enviados; si vuelves a compartir, recibes uno nuevo.",
                    'shot' => 'share',
                    'alt' => 'El panel de compartir de una lista, con el enlace, el botón para copiarlo y el botón para dejar de compartir.',
                ],
                [
                    'title' => 'Con amigos por su nombre',
                    'body' => "1. Toca «Compartir» y luego «Compartir con amigos».\n2. Elige los [amigos](friends) que pueden verla.\n3. Toca «Enviar».\n\nReciben un correo con el enlace, sin el contenido, y la lista aparece en su [página de amigos](friends). «Dejar de compartir con …» la quita de ahí; un enlace que ya tuvieran sigue funcionando hasta que dejes de compartir. Cómo hacerse amigos está en [Amigos, cumpleaños y recordatorios](lists-help/friends).",
                ],
                [
                    'title' => 'Quién puede añadir',
                    'body' => 'Con «Cualquiera puede añadir regalos» activado, quien tiene el enlace pone algo en la lista directamente. Desactivado, las propuestas te llegan a ti y decides. Los artículos escritos a mano siempre te esperan.',
                ],
                [
                    'title' => 'Quién ve lo que se ha comprado',
                    'body' => 'En una [lista de deseos](lists-help/kinds) no ves lo reservado. Está desactivado por defecto y lo activas por lista con «Muéstrame lo que ya está reservado». En una lista de regalos está activado, porque también regalas. Los nombres de quién compra qué están ocultos por defecto; si los activas, vale solo para reservas nuevas.',
                ],
                [
                    'title' => 'Dirección de entrega',
                    'body' => 'En tu propia lista de deseos puedes guardar una dirección de entrega. Se guarda cifrada y solo aparece a quien haya [reservado](lists-help/claiming) algo. Si esa persona suelta la reserva, desaparece de nuevo.',
                ],
            ],
        ],

        'claiming' => [
            'title' => 'Comprar de una lista compartida',
            'blurb' => 'Reservar, soltar, marcar como comprado, proponer algo, y el quiz.',
            'seo_description' => 'Lo que puedes hacer en una lista de deseos que compartieron contigo: reservar lo que compras, proponer algo, y jugar al quiz.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Reservar',
                    'body' => "1. Abre el enlace que te enviaron.\n2. Toca «Yo lo regalo» en el regalo que compras.\n3. Inicia sesión si te lo piden; tu toque se ejecuta después.\n\nAsí nadie más lo compra también. La persona para quien es la lista no ve nada.",
                    'shot' => 'shared',
                    'alt' => 'Dos regalos en una lista compartida, cada uno con el botón para decir que lo regalas tú.',
                ],
                [
                    'title' => 'Al final no, o comprado',
                    'body' => '«Mejor no» lo suelta de nuevo, cuando quieras. «Ya lo he comprado» lo marca como comprado. Arriba ves cuánto está ya reservado.',
                ],
                [
                    'title' => 'Proponer algo',
                    'body' => "1. Busca al pie de la lista lo que quieres proponer, o descríbelo tú mismo.\n2. Toca «Sugerir algo», o «Añadir a la lista» donde se permita directamente.\n\nQuien gestiona la lista ve tu propuesta y decide. Si se permite directamente o no está explicado en [Compartir una lista](lists-help/sharing).",
                ],
                [
                    'title' => 'Guárdalo también para ti',
                    'body' => 'Cada artículo tiene un marcador y «Añadir a mi lista». Lo que copias llega a [tu lista](lists) sin la reserva.',
                ],
                [
                    'title' => 'El quiz: ¿cuánto los conoces?',
                    'body' => 'En una lista compartida con al menos cinco artículos, quien la gestiona puede crear un quiz. Cinco rondas, cuatro productos cada vez de los que solo uno está de verdad en la lista, y una puntuación para compartir. Quien gestiona la lista no juega y solo ve cuántos jugaron y la puntuación media.',
                ],
            ],
        ],

        'group' => [
            'title' => 'Regalar entre varios',
            'blurb' => 'Regalo en grupo, votos, aportaciones, y conversación con quienes participan.',
            'seo_description' => 'Comprar un regalo entre varios: reunir ideas, votar, acordar quién aporta qué, y hablarlo.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Un regalo en grupo',
                    'body' => "1. Crea una [lista nueva](lists-help/saving) y elige «Entre varios, para alguien» en el primer paso.\n2. Di para quién es y ponle un nombre.\n3. Comparte el enlace con quienes participan.\n\nCualquiera con el enlace puede añadir ideas y votarlas. No hay nada que reservar: es un solo regalo de todos vosotros. Sortear nombres es otra cosa; está en [Amigo invisible](lists-help/santa).",
                ],
                [
                    'title' => 'Votar',
                    'body' => 'Cada idea tiene «Vota por esto» y un contador. El orden no cambia mientras miras; los contadores sí.',
                ],
                [
                    'title' => 'Aportar',
                    'body' => 'En «Cómo aporta cada uno» eliges: cada uno elige su importe, o todos lo mismo. Quien participa toca «Me apunto» y ve su parte y el total. Solo quien organiza ve quién aporta qué, salvo que active «Todos ven quién contribuye». Aquí no se mueve dinero; eso lo arregláis entre vosotros.',
                ],
                [
                    'title' => 'Hablarlo',
                    'body' => 'Junto a la lista está «Conversación», un hilo para todos los que tienen el enlace. La persona para quien es la lista no lo lee. Publicas con tu nombre; puedes quitar tus propios mensajes, quien gestiona la lista todos. Una lista privada no tiene conversación.',
                ],
            ],
        ],

        'santa' => [
            'title' => 'Amigo invisible',
            'blurb' => 'Sortear nombres sin papelitos: un grupo, un presupuesto, una fecha, y cada uno recibe un nombre.',
            'seo_description' => 'Sortear nombres para el Amigo invisible: crea un grupo, invita a todos con un enlace, sortea, y une una lista de deseos.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Crear un grupo',
                    'body' => "1. Ve a [Amigo invisible](santa).\n2. Toca «Crear un grupo».\n3. Ponle al grupo un nombre, un presupuesto y la fecha en que dais los regalos.\n\nTú organizas.",
                    'shot' => 'santa',
                    'alt' => 'La página del Amigo invisible, con el botón para crear un grupo.',
                ],
                [
                    'title' => 'Invitar a todos',
                    'body' => "1. Copia el enlace de invitación del grupo.\n2. Envíaselo a todos los que participan.\n3. Quien lo abre rellena un nombre y un correo. No hace falta cuenta.",
                ],
                [
                    'title' => 'Sortear',
                    'body' => "1. Espera a que estén todos, al menos dos personas.\n2. Toca «Hacer el sorteo».\n\nCada uno recibe un nombre por correo. Quien organiza nunca ve las parejas, así que tú también te llevas la sorpresa.",
                ],
                [
                    'title' => 'Si alguien se retira',
                    'body' => 'Quítalo del grupo, o elige «Volver a sortear para esta persona». Solo se vuelven a sortear las parejas afectadas, y solo esas personas reciben un correo nuevo.',
                ],
                [
                    'title' => 'Unir mi lista de deseos al grupo',
                    'body' => "1. Abre tu [lista de deseos](lists).\n2. Toca «Usar esta lista» junto al grupo.\n\nQuien te tocó ve así qué te gustaría, sin que tú veas quién es. ¿Aún no tienes lista? [Crea una](lists-help/saving) en tres pasos.",
                ],
                [
                    'title' => 'Un recordatorio antes',
                    'body' => 'Treinta, quince y dos días antes de la fecha recibes un aviso, aquí y por correo. Más en [Amigos, cumpleaños y recordatorios](lists-help/friends).',
                ],
            ],
        ],

        'friends' => [
            'title' => 'Amigos, cumpleaños y recordatorios',
            'blurb' => 'Quiénes son tus amigos, qué ven, y cuándo recibes un aviso.',
            'seo_description' => 'Añadir amigos, guardar cumpleaños, y recibir un recordatorio a tiempo para un cumpleaños o una ocasión.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Hacerse amigos',
                    'body' => "Cuando alguien abre tu enlace compartido con la sesión iniciada, sois [amigos](friends). Para añadir a alguien tú mismo:\n\n1. Ve a [Amigos](friends).\n2. En «Añadir a alguien», escribe un correo, y el cumpleaños si quieres.\n3. Toca «Añadir».\n\nEsa persona no recibe ningún correo por ello. Si ya tiene cuenta, quedáis conectados al momento; si no, en cuanto inicie sesión.",
                    'shot' => 'friends',
                    'alt' => 'La página de amigos, con el formulario para añadir a alguien por su correo.',
                ],
                [
                    'title' => 'Qué ve un amigo',
                    'body' => 'La [página de amigos](friends) muestra, por amigo, su cumpleaños, las listas que compartió contigo y cuáles de tus listas ve. Lo reservado nunca aparece ahí. Quitar a un amigo quita la conexión por ambos lados; listas y reservas se quedan.',
                ],
                [
                    'title' => 'Cumpleaños',
                    'body' => 'De un amigo guardas el día y el mes, nunca el año. Es tu nota. Tu propio cumpleaños va en tu cuenta, con la opción de que tus amigos lo vean o no.',
                ],
                [
                    'title' => 'Recordatorios',
                    'body' => 'Treinta, quince y dos días antes recibes un aviso para un cumpleaños, la fecha de un [Amigo invisible](lists-help/santa) y la [ocasión](lists-help/kinds) de una lista. Aquí y por correo. El correo nombra la fecha y el enlace, nunca lo que hay en la lista.',
                ],
                [
                    'title' => 'Avisos',
                    'body' => 'En [Avisos](notifications) ves lo que pasó: alguien compartió una lista contigo, añadió o propuso algo, hay un mensaje nuevo en una conversación, un producto vuelve a estar en stock, una búsqueda que sigues tiene novedades. Abrir la página lo marca todo como leído.',
                ],
            ],
        ],

        'alerts' => [
            'title' => 'Vigilar precios y stock',
            'blurb' => 'El precio que baja, un producto que vuelve, y una búsqueda que sigues.',
            'seo_description' => 'Ver cuándo baja un precio, recibir un aviso cuando un producto vuelve a estar en stock, y seguir una búsqueda.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Guárdalo, y el precio se sigue solo',
                    'body' => 'Un producto en tu [lista](lists) recuerda el precio de ese momento. Si baja, lo ves en la ficha, con el precio antiguo tachado. No hace falta más.',
                ],
                [
                    'title' => 'De vuelta en stock',
                    'body' => "1. Abre la página de un producto que ya no tiene ninguna tienda.\n2. Toca «Avísame cuando vuelva».\n\nRecibes un aviso en cuanto una tienda lo tenga de nuevo. Se para en el mismo sitio con «Dejar de vigilar».",
                ],
                [
                    'title' => 'Seguir una búsqueda',
                    'body' => "1. [Busca](search) lo que quieres seguir.\n2. Encima de los resultados, toca «Avísame de novedades».\n3. Pon un precio máximo si quieres y toca «Seguir esta búsqueda».\n\nCada mañana miramos si hay algo nuevo que encaje, y lo ves en [Avisos](notifications). Se para en la misma página de búsqueda con «Parar».",
                    'shot' => 'watch',
                    'alt' => 'El botón para seguir una búsqueda, con debajo el campo para un precio máximo y el botón para confirmar.',
                ],
            ],
        ],
    ],
];
