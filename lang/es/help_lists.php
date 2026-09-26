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
        'seo_description' => 'Todo lo que permite una lista: guardar, compartir, regalar juntos, Amigo invisible, amigos y recordatorios. Paso a paso, con imágenes.',
        'intro' => 'Una [lista de deseos](lists) guarda lo que [encuentras](search) aquí, para ti o para otra persona. Abajo, lo que puedes hacer con ella y cómo, tema por tema, con imágenes. Empieza por el primero si nunca has guardado nada.',
        'back' => 'Todos los temas',
        'next' => 'Siguiente',
        'cta_search' => 'Buscar algo que guardar',
        'cta_lists' => 'Ir a Mis Coves',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Guardar y crear una lista',
            'blurb' => 'Encontrar algo, guardarlo, abrir tus listas, y crear una lista en un paso.',
            'seo_description' => 'Guarda todo lo que encuentres aquí en una lista de deseos y crea una en un paso. Con imágenes.',
            'intro' => 'No hace falta crear una lista antes. Al guardar se te ofrece, y la portada tiene un botón que crea una en un paso.',
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
                    'body' => 'Toca el marcador y ya está en tu lista. Toca otra vez para elegir otra lista o empezar una nueva. El panel pone arriba la lista que usa un solo toque, y marca cada lista donde ya está.',
                    'shot' => 'choose',
                    'alt' => 'El panel abierto junto a un producto, con las listas donde guardar y la opción de empezar una nueva.',
                ],
                [
                    'title' => 'Abre tus listas',
                    'body' => 'Todo lo que guardaste está en [Mis Coves](lists), en una sola página: Listas de deseos, Para otros, Regalar juntos y Guardadas, cada una con su número. Cada lista muestra qué contiene, si es privada y para quién es. Cuando baja un precio, la ficha lo dice.',
                    'shot' => 'lists',
                    'alt' => 'La página Mis Coves, con dos listas y el botón para crear una.',
                ],
                [
                    'title' => 'Crear una lista en un paso',
                    'body' => "1. Toca «Crear una Cove» en [Mis Coves](lists) o en la portada.\n2. Elige para quién es: «Para mi», «Para otra persona» o «Entre varios, para alguien». Para otra persona, escribe su nombre o toca a alguien que ya tengas.\n3. Toca «Crear lista». El nombre ya viene puesto; cámbialo antes si quieres.\n\nLa lista se abre con la casilla para añadir ya abierta: pega un enlace o busca. La ocasión, compartir y pedir ideas están en la propia lista: Compartir, y Ajustes bajo Más.\n\nO sáltate esto: al guardar, toca el marcador y elige ahí una lista nueva. Lo que estabas guardando entra en ella al momento.\n\nUna elección queda fija después: para quién es. Todo lo demás se puede cambiar. Lo que permite cada tipo está en [Lista de deseos, lista de regalos o regalar juntos](lists-help/kinds).",
                    'shot' => 'wizard',
                    'alt' => 'Una lista nueva: la única pregunta, para quién es.',
                ],
                [
                    'title' => 'No hace falta iniciar sesión para empezar',
                    'body' => 'Funciona sin cuenta: el botón te hace iniciar sesión y la lista está ahí. Lo que rellenaste se guarda un día, así que puedes ausentarte.',
                ],
            ],
        ],

        'kinds' => [
            'title' => 'Lista de deseos, lista de regalos o regalar juntos',
            'blurb' => 'La única elección que queda fija, y lo que permite cada tipo de lista.',
            'seo_description' => 'Tres tipos de lista: una lista de deseos para ti, una lista de regalos para otra persona, o una lista para regalar juntos. Qué permite cada una y qué queda fijo.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Una elección queda fija',
                    'body' => 'La única pregunta de una [lista nueva](lists-help/saving) es para quién es. Eso decide lo que la lista permite, y es lo único que no se cambia después. El nombre, la ocasión y quién la ve se pueden cambiar siempre.',
                    'shot' => 'wizard',
                    'alt' => 'Una lista nueva, con los tres tipos para elegir.',
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
                    'title' => 'Entre varios, para alguien: regalar juntos',
                    'body' => 'Un regalo, varios que regalan. Cualquiera con el enlace puede añadir ideas, votarlas y decir con cuánto contribuye. Aquí no se mueve dinero; eso lo arregláis entre vosotros. Más en [Regalar entre varios](lists-help/group).',
                ],
                [
                    'title' => 'Una ocasión y una fecha',
                    'body' => 'Cualquier lista puede llevar una ocasión: cumpleaños, Navidad, boda, nacimiento, y diez más. Para un cumpleaños, Navidad y San Valentín, la fecha se rellena sola. Con una fecha, recibes un [recordatorio](lists-help/friends) a tiempo.',
                    'shot' => 'occasion',
                    'alt' => 'El panel Ocasión de una lista, con la elección de la ocasión y la fecha.',
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
                    'body' => 'En cada ficha de producto, en la [búsqueda](search) y en cada [Cove](cove). Un toque guarda en la última lista donde guardaste, si no en tu lista por defecto. Una vez guardado, el marcador está relleno y dice Guardado, y otro toque abre el panel: marca otra lista, o desmarca una para quitarlo. En la página de un producto es el mismo botón con la palabra Guardar al lado, y en una Cove también.',
                ],
                [
                    'title' => 'Añadir desde la lista',
                    'body' => "1. Abre tu lista en [Mis Coves](lists).\n2. Toca «+ Añadir un producto».\n3. Escribe lo que buscas y pulsa Intro, o toca el icono de escanear y apunta con la cámara al código de barras.\n4. Toca el producto en los resultados. Entra directamente en tu lista.",
                    'shot' => 'add',
                    'alt' => 'El campo de búsqueda al principio de una lista para añadir un producto, con debajo el enlace para añadir un artículo offline.',
                ],
                [
                    'title' => 'Algo que no está en este sitio',
                    'body' => "1. Toca «+ Añadir un producto».\n2. Bajo el campo de búsqueda, elige «Añadir un artículo offline».\n3. Escribe qué es. Puede llevar un enlace, un precio y una nota como «talla M, en azul».\n4. Guárdalo.\n\nTus propios artículos se pueden cambiar después: toca «⋯» en el artículo y luego «Editar». Los del catálogo no: su título y precio vienen de la tienda.",
                ],
                [
                    'title' => 'Copiar, no mover',
                    'body' => 'En tu propia lista, «Copiar a otra lista» está bajo «⋯» en cada artículo. En la [lista compartida](lists-help/claiming) de otra persona es «Añadir a mi lista». El original se queda; la nota y el precio van con él, quien lo compra no.',
                ],
                [
                    'title' => 'Cuando baja el precio',
                    'body' => 'Un producto guardado recuerda el precio de ese momento. Si baja, la ficha muestra el nuevo precio con el antiguo tachado. No hay nada que configurar. Más en [Vigilar precios y stock](lists-help/alerts).',
                    'shot' => 'drop',
                    'alt' => 'Un artículo de una lista cuyo precio bajó, con el precio nuevo y el antiguo tachado.',
                ],
                [
                    'title' => 'Quitar',
                    'body' => 'Toca «⋯» en el artículo y elige «Quitar de esta lista». Solo quien gestiona la lista puede quitar un artículo. Lo más nuevo está arriba.',
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
                    'body' => "1. Toca «Compartir» y luego «Compartir con amigos».\n2. Elige los [amigos](people) que pueden verla.\n3. Toca «Enviar».\n\nReciben un correo con el enlace, sin el contenido, y la lista aparece junto a tu nombre en su página [Mi gente](people). «Dejar de compartir con …» la quita de ahí; un enlace que ya tuvieran sigue funcionando hasta que dejes de compartir. Cómo hacerse amigos está en [Mi gente, cumpleaños y recordatorios](lists-help/friends).",
                    'shot' => 'friends-share',
                    'alt' => 'La parte del panel de compartir donde eliges amigos y les envías el enlace.',
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
                    'body' => "1. Abre el enlace a la [lista compartida](lists-help/sharing) que te enviaron.\n2. Toca «Yo lo regalo» en el regalo que compras.\n3. Inicia sesión si te lo piden; tu toque se ejecuta después.\n\nAsí nadie más lo compra también. La persona para quien es la lista no ve nada.",
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
            ],
        ],

        'quiz' => [
            'title' => 'El quiz: ¿cuánto los conoces?',
            'blurb' => 'Un juego hecho con tu lista compartida: cuatro productos, solo uno está de verdad en ella.',
            'seo_description' => 'Crea un quiz con tu lista de deseos: ¿quién te conoce mejor? Cinco rondas, una puntuación para compartir.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Qué es el quiz',
                    'body' => 'Un juego hecho con una [lista de deseos](lists-help/kinds) compartida. Cinco rondas, cuatro productos cada vez de los que solo uno está de verdad en la lista, y al final una puntuación para compartir. Quien gestiona la lista crea el quiz; quien recibe el enlace juega.',
                ],
                [
                    'title' => 'Crear un quiz',
                    'body' => '1. Abre tu lista. Tiene que estar [compartida](lists-help/sharing) y tener al menos cinco artículos.
2. Toca «Más» y luego «Quiz».
3. Toca «Crear un quiz con esta lista».
4. Pasa el enlace.',
                    'shot' => 'quiz',
                    'alt' => 'El panel del quiz de una lista, con el botón para crear uno.',
                ],
                [
                    'title' => 'Jugar',
                    'body' => '1. Abre el enlace del quiz.
2. En cada ronda, elige cuál de los cuatro productos está de verdad en la lista.
3. Toca «Ver tu resultado», y luego «Comparte tu resultado» si quieres.

Cada uno juega una vez.',
                ],
                [
                    'title' => 'Qué ves como creador',
                    'body' => 'Tú no juegas; sería hacer trampa. Ves cuántas personas jugaron y la puntuación media, no quién respondió qué.',
                ],
            ],
        ],

        'group' => [
            'title' => 'Regalar entre varios',
            'blurb' => 'Regalar juntos, votos, aportaciones, y conversación con quienes participan.',
            'seo_description' => 'Comprar un regalo entre varios: reunir ideas, votar, acordar quién aporta qué, y hablarlo.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Regalar juntos',
                    'body' => "1. Crea una [lista nueva](lists-help/saving) y elige «Entre varios, para alguien».\n2. Di para quién es; el nombre ya viene puesto.\n3. Comparte el enlace con quienes participan.\n\nCualquiera con el enlace puede añadir ideas y votarlas. No hay nada que reservar: es un solo regalo que [regaláis juntos](lists-help/kinds). Sortear nombres es otra cosa; está en [Amigo invisible](lists-help/santa).",
                    'shot' => 'group',
                    'alt' => 'La página de una lista para regalar juntos, con las ideas para votar y la casilla para aportar.',
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
                    'body' => 'Debajo de la lista está «Conversación», un hilo para todos los que tienen el enlace. La persona para quien es la lista no lo lee. Publicas con tu nombre; puedes quitar tus propios mensajes, quien gestiona la lista todos. Una lista privada no tiene conversación.',
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
                    'body' => "Elige tu lista bajo «Tu lista de deseos» al crear el grupo, o después en la página del grupo. También funciona al revés: abre tu [lista de deseos](lists) y toca «Más», luego «Amigo invisible» y «Usar esta lista» junto al grupo.\n\nQuien te tocó ve así qué te gustaría, sin que tú veas quién es. ¿Aún no tienes lista? [Crea una](lists-help/saving) en un paso.",
                ],
                [
                    'title' => 'Un recordatorio antes',
                    'body' => 'Treinta, quince y dos días antes de la fecha recibes un aviso, aquí y por correo. Más en [Mi gente, cumpleaños y recordatorios](lists-help/friends).',
                ],
            ],
        ],

        'friends' => [
            'title' => 'Mi gente, cumpleaños y recordatorios',
            'blurb' => 'Todas las personas a las que compras, tus amigos en GiftCoves, y cuándo recibes un aviso.',
            'seo_description' => 'Todas las personas a las que compras en un solo sitio, hacerse amigos, guardar cumpleaños, y recibir un recordatorio a tiempo para un cumpleaños o una ocasión.',
            'intro' => null,
            'numbered' => false,
            'sections' => [
                [
                    'title' => 'Todas las personas a las que compras, en una página',
                    'body' => '[Mi gente](people) muestra a todas las personas a las que compras. A algunas las guardaste tú y solo tú las ves. Otras son amigos en GiftCoves, marcados «en GiftCoves». Para añadir a alguien que solo ves tú, toca «Añadir a alguien»: un nombre y, si quieres, quién es para ti y su cumpleaños.',
                    'shot' => 'friends',
                    'alt' => 'La página Mi gente, con los botones para añadir a alguien e invitar a un amigo.',
                ],
                [
                    'title' => 'Hacerse amigos',
                    'body' => "Cuando alguien abre tu enlace compartido con la sesión iniciada, sois amigos. Para invitar a alguien tú mismo:\n\n1. Ve a [Mi gente](people).\n2. Toca «Invitar a GiftCoves» y escribe su correo, y el cumpleaños si quieres.\n3. Toca «Invitar».\n\nEsa persona no recibe ningún correo por ello, así que díselo tú. Si ya tiene cuenta, quedáis conectados al momento; si no, en cuanto inicie sesión.",
                ],
                [
                    'title' => 'Qué ve un amigo',
                    'body' => 'En [Mi gente](people), «Detalles» en un amigo muestra su cumpleaños, las listas que compartió contigo y cuáles de tus listas ve. Lo reservado nunca aparece ahí. Quitar a un amigo quita la conexión por ambos lados; listas y reservas se quedan.',
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
