<?php

declare(strict_types=1);

/**
 * The list help pages, in Spanish. See lang/nl/help_lists.php for the shape,
 * the [words](path) link syntax, and why this is not in site.php.
 */
return [
    'index' => [
        'title' => 'Cómo funcionan las listas',
        'seo_title' => 'Cómo funcionan las listas',
        'seo_description' => 'Todo lo que permite una lista: guardar, compartir, regalar entre varios, Amigo invisible, amigos y recordatorios. Explicado por tema.',
        'intro' => 'Una lista guarda lo que encuentras aquí, para ti o para otra persona. Abajo, lo que puedes hacer con ella, tema por tema. Empieza por el primero si nunca has guardado nada.',
        'back' => 'Todos los temas',
        'next' => 'Siguiente',
        'cta_search' => 'Buscar algo que guardar',
        'cta_lists' => 'Ir a mis listas',
    ],

    'topics' => [
        'saving' => [
            'title' => 'Guardar y crear una lista',
            'blurb' => 'Tres pasos, con imágenes: encontrar algo, guardarlo, abrir tus listas.',
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
                    'title' => 'Crear una lista',
                    'body' => "De dos maneras, y casi todo el mundo usa la primera.\n\n- Al guardar: toca el marcador y elige una lista nueva. Lo que estabas guardando entra en ella al momento.\n- Con el botón «Crear una lista nueva» en la portada o «Nueva lista» en [Mis listas](lists): tres pasos, para quién es, nombre y ocasión, y si se queda privada o la compartes después.\n\nUna elección queda fija después: para quién es. Todo lo demás se puede cambiar. Lo que permite cada tipo está en [Lista de deseos, lista de regalos o regalo en grupo](lists-help/kinds).",
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
                    'body' => 'La página de una lista tiene «Añadir un producto». Busca ahí, o escanea un código de barras, y lo que elijas entra directamente en esa lista.',
                ],
                [
                    'title' => 'Algo que no está en este sitio',
                    'body' => 'En «Añadir un producto», elige «Ponerlo tú mismo». Con un nombre basta; puede llevar un enlace, un precio y una nota como «talla M, en azul». Tus propios artículos se pueden editar después. Los del catálogo no: su título y precio vienen de la tienda.',
                ],
                [
                    'title' => 'Copiar, no mover',
                    'body' => 'Cada artículo tiene «Copiar a otra lista». En la [lista compartida](lists-help/claiming) de otra persona es «Poner en mi lista». El original se queda; la nota y el precio van con él, quien lo compra no.',
                ],
                [
                    'title' => 'Cuando baja el precio',
                    'body' => 'Un producto guardado recuerda el precio de ese momento. Si baja, la ficha muestra el nuevo precio con el antiguo tachado. No hay nada que configurar. Más en [Vigilar precios y stock](lists-help/alerts).',
                ],
                [
                    'title' => 'Quitar',
                    'body' => 'Solo quien gestiona la lista puede quitar un artículo, y antes se pide confirmación. Lo más nuevo está arriba.',
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
                    'body' => 'Copia el enlace, o un mensaje corto con el enlace dentro, o envíalo por WhatsApp, Telegram, correo y más. Cualquiera con el enlace ve la lista. «Dejar de compartir» invalida todos los enlaces enviados; si vuelves a compartir, recibes uno nuevo.',
                ],
                [
                    'title' => 'Con amigos por su nombre',
                    'body' => 'Elige [amigos](friends) y envía. Reciben un correo con el enlace, sin el contenido, y la lista aparece en su [página de amigos](friends). «Dejar de compartir con …» la quita de ahí; un enlace que ya tuvieran sigue funcionando hasta que dejes de compartir. Cómo hacerse amigos está en [Amigos, cumpleaños y recordatorios](lists-help/friends).',
                ],
                [
                    'title' => 'Quién puede añadir',
                    'body' => 'Con «Cualquiera puede añadir regalos» activado, quien tiene el enlace pone algo en la lista directamente. Desactivado, las propuestas te llegan a ti y decides. Los artículos escritos a mano siempre te esperan.',
                ],
                [
                    'title' => 'Quién ve lo que se ha comprado',
                    'body' => 'En una [lista de deseos](lists-help/kinds) no ves lo reservado. Está desactivado por defecto y lo activas por lista con «Mostrarme lo que está reservado». En una lista de regalos está activado, porque también regalas. Los nombres de quién compra qué están ocultos por defecto; si los activas, vale solo para reservas nuevas.',
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
                    'body' => '«Yo lo regalo» lo reserva para ti, para que nadie más lo compre también. Hace falta una cuenta; inicia sesión y tu toque se ejecuta igualmente. La persona para quien es la lista no ve nada.',
                ],
                [
                    'title' => 'Al final no, o comprado',
                    'body' => '«Al final no» lo suelta de nuevo, cuando quieras. «Ya lo he comprado» lo marca como comprado. Arriba ves cuánto está ya reservado.',
                ],
                [
                    'title' => 'Proponer algo',
                    'body' => 'Busca al pie de la lista y elige «Proponerlo», o «Añadir a la lista» donde se permita directamente. También puedes describir algo tú mismo. Quien gestiona la lista ve tu propuesta y decide. Si se permite directamente o no está explicado en [Compartir una lista](lists-help/sharing).',
                ],
                [
                    'title' => 'Guárdalo también para ti',
                    'body' => 'Cada artículo tiene un marcador y «Poner en mi lista». Lo que copias llega a [tu lista](lists) sin la reserva.',
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
                    'body' => 'Al [crear](lists-help/saving) la lista, elige «Entre varios, para alguien». Cualquiera con el enlace puede añadir ideas y votarlas. No hay nada que reservar: es un solo regalo de todos vosotros. Sortear nombres es otra cosa; está en [Amigo invisible](lists-help/santa).',
                ],
                [
                    'title' => 'Votar',
                    'body' => 'Cada idea tiene «Votar esta» y un contador. El orden no cambia mientras miras; los contadores sí.',
                ],
                [
                    'title' => 'Aportar',
                    'body' => 'En «Cómo aporta cada uno» eliges: cada uno elige su importe, o todos lo mismo. Quien participa toca «Me apunto» y ve su parte y el total. Solo quien organiza ve quién aporta qué, salvo que active «Todos ven quién aporta». Aquí no se mueve dinero; eso lo arregláis entre vosotros.',
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
                    'body' => 'Ve a [Amigo invisible](santa) y elige «Crear un grupo». Ponle un nombre, un presupuesto y la fecha en que dais los regalos. Tú organizas.',
                ],
                [
                    'title' => 'Invitar a todos',
                    'body' => 'Pasa el enlace de invitación. Se entra con un nombre y un correo; no hace falta cuenta.',
                ],
                [
                    'title' => 'Sortear',
                    'body' => 'En cuanto haya al menos dos personas, elige «Sortear». Cada uno recibe un nombre por correo. Quien organiza nunca ve las parejas, así que tú también te llevas la sorpresa.',
                ],
                [
                    'title' => 'Si alguien se retira',
                    'body' => 'Quítalo del grupo, o vuelve a sortear para una sola persona. Solo se vuelven a sortear las parejas afectadas, y solo esas personas reciben un correo nuevo.',
                ],
                [
                    'title' => 'Una lista para acompañar',
                    'body' => 'Quien tenga una [lista de deseos](lists) la une al grupo con «Usar esta lista» en la propia lista. Así quien te tocó sabe qué te gustaría. Cómo compartirla está en [Compartir una lista](lists-help/sharing).',
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
                    'body' => 'Cuando alguien abre tu enlace compartido con la sesión iniciada, sois [amigos](friends). También puedes añadir a alguien por su correo; esa persona no recibe ningún correo por ello.',
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
                    'title' => 'Notificaciones',
                    'body' => 'En [Notificaciones](notifications) ves lo que pasó: alguien compartió una lista contigo, añadió o propuso algo, hay un mensaje nuevo en una conversación, un producto vuelve a estar en stock, una búsqueda que sigues tiene novedades. Abrir la página lo marca todo como leído.',
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
                    'body' => 'Cuando ninguna tienda tiene ya un producto, su página dice «Avísame cuando vuelva». Recibes un aviso en cuanto una tienda lo tenga de nuevo. Se para en el mismo sitio.',
                ],
                [
                    'title' => 'Seguir una búsqueda',
                    'body' => '[Busca](search) algo y elige «Mantenme al tanto», con un precio máximo si quieres. Cada mañana miramos si hay algo nuevo que encaje, y lo ves en [Notificaciones](notifications). Se para en la misma página de búsqueda.',
                ],
            ],
        ],
    ],
];
