# Bonjour
Voici une librairie penssée pour codeIgniter qui authentifie une application sur Flickr (OAuth 1.0).
Comme je ne trouvais pas de solution à ma convenance pour réaliser cette authentification, alors j'ai commis ceci, je le mets là pour si ça peut aider ou éclairer quelqu'un, parce que la doc de Flickr, c'est pas vraiment ça.

Voici comment l'utiliser:

Copier Loicbcn_flickr.php dans application/librairies

Dans votre controller, charger la librairie et appeler la méthode getPhotos qui est la pour faire un test:

        $this->load->library('Loicbcn_flickr');
        // getPhotos par défaut
        $photos = $this->loicbcn_flickr->getPhotos();
        // demander des paramètres supplémentaires
        $photos = $this->loicbcn_flickr->getPhotos(array("extras" => "z, url_c, url_l, url_o"));

Au premier appel, vous serez redirigé vers Flickr pour autoriser l'accès de l'application au compte twitter.
Ensuite, les données d'accès sont conservées dans application/cache/

Il semble que l' autorisation renvoyée est permanente (il est possible de la révoquer depuis son compte twitter), il est aussi possible de supprimer les fichiers:

    - cache/flickr_request_token.php
    - cache/flickr_access_token.php

À l'appel suivant, le processus d'authentification sera relancé.

Bon, c'est un peu concis, désolé, mais c'est ainsi.