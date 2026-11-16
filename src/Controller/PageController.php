<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use App\Form\ContactFormType;
use App\Entity\Contact;
use App\Entity\Category;
use Symfony\Component\String\Slugger\SluggerInterface;
use App\Form\PostFormType;
use App\Entity\Post;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use App\Entity\Comment;
use App\Form\CommentFormType;


final class PageController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function home(ManagerRegistry $doctrine): Response
    {
        $repository = $doctrine->getRepository(Category::class);
        $categories = $repository->findAll();

        return $this->render('page/index.html.twig', [
            'categories' => $categories,
        ]);
    }
    
    #[Route('/about', name: 'about')]
    public function about(): Response
    {
        return $this->render('page/about.html.twig', []);
    }

private const POSTS_PER_PAGE = 2; //Ponlo a 1 para pruebas

   #[Route('/blog/{page}', name: 'blog', requirements: ['page' => '\d+'])]
public function index(ManagerRegistry $doctrine, int $page = 1): Response
{
    $page = max(1, $page);

    $repository = $doctrine->getRepository(Post::class);
    //Aquí obtenemos los datos con el método creado en el repositorio
    $posts = $repository->findPaginados($page, self::POSTS_PER_PAGE);
    $recents = $repository->findRecents();

    $total = count($posts);
    $totalPages = max(1, (int) ceil($total / self::POSTS_PER_PAGE));

    // Si piden una página que no existe, redirigimos a la última
    if ($page > $totalPages) {
        return $this->redirectToRoute('blog', ['page' => $totalPages]);
    }

    return $this->render('page/blog.html.twig', [
        'posts' => $posts,
        'recents' => $recents,
        'page'       => $page,
        'totalPages' => $totalPages,
        'total'        => $total,
    ]);
}

#[Route('/blog/buscar/{page}', name: 'blog_buscar', requirements: ['page' => '\d+'])]
public function buscar(ManagerRegistry $doctrine, Request $request, int $page = 1): Response
{
    $page = max(1, $page);

    $repository = $doctrine->getRepository(Post::class);
    $searchTerm = trim($request->query->get('searchTerm', ''));
    $posts = $repository->findBuscadosPaginados($searchTerm, $page, self::POSTS_PER_PAGE);
    $recents = $repository->findRecents();

    $total = count($posts);
    $totalPages = max(1, (int) ceil($total / self::POSTS_PER_PAGE));

    // Si piden una página que no existe, redirigimos a la última (conservando la búsqueda)
    if ($page > $totalPages) {
        return $this->redirectToRoute('blog_buscar', ['page' => $totalPages, 'searchTerm' => $searchTerm]);
    }

    return $this->render('page/blog.html.twig', [
        'posts'      => $posts,
        'recents'    => $recents,
        'searchTerm' => $searchTerm,
        'page'       => $page,
        'totalPages' => $totalPages,
        'total'      => $total,
    ]);
}

    
    #[Route('/single_post/{slug}', name: 'single_post')]
    public function post(ManagerRegistry $doctrine, Request $request, $slug): Response
    {
        $repository = $doctrine->getRepository(Post::class);
        $post = $repository->findOneBy(["slug"=>$slug]);
        $recents = $repository->findRecents();
        $comment = new Comment();
        $form = $this->createForm(CommentFormType::class, $comment);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $comment = $form->getData();
            $comment->setPost($post);  
            //Aumentamos en 1 el número de comentarios del post
            $post->setNumComments(($post->getNumComments() ?? 0) + 1);
            $entityManager = $doctrine->getManager();    
            $entityManager->persist($comment);
            $entityManager->flush();
            return $this->redirectToRoute('single_post', ["slug" => $post->getSlug()]);
        }
        return $this->render('blog/single_post.html.twig', [
            'post' => $post,
            'recents' => $recents,
            'commentForm' => $form->createView()
        ]);
    }

    #[Route('/single_post/{slug}/like', name: 'post_like')]
    public function like(ManagerRegistry $doctrine, $slug): Response
    {
        $repository = $doctrine->getRepository(Post::class);
        $post = $repository->findOneBy(["slug" => $slug]);
        if ($post) {
            // Haz un método llamado like() en la entidad Post que aumente en 1 numLikes
            $post->like();
            $entityManager = $doctrine->getManager();    
            $entityManager->persist($post);
            $entityManager->flush();

            return $this->redirectToRoute('single_post', ["slug" => $post->getSlug()]);
        }

        return $this->redirectToRoute('blog');
    }



    #[Route('/contact', name: 'contact')]
    public function contact(ManagerRegistry $doctrine, Request $request): Response
    {
        $contact = new Contact();
        $form = $this->createForm(ContactFormType::class, $contact);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $contacto = $form->getData();    
        $entityManager = $doctrine->getManager();    
        $entityManager->persist($contacto);
        $entityManager->flush();
        return $this->redirectToRoute('thankyou', []);
    }
    return $this->render('page/contact.html.twig', array(
        'form' => $form->createView()    
    ));
    }
    #[Route('/thankyou', name: 'thankyou')]
    public function thankyou(): Response
    {
        return $this->render('page/thankyou.html.twig', []);
    }
    #[Route('/blog/new', name: 'new_post')]
    #[IsGranted('ROLE_USER')]
    public function newPost(ManagerRegistry $doctrine, Request $request, SluggerInterface $slugger): Response
    {
        $post = new Post();
        $form = $this->createForm(PostFormType::class, $post);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $post = $form->getData();

            // Procesar la imagen subida
            $imageFile = $form->get('image')->getData();
            if ($imageFile) {
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->guessExtension();

                try {
                    $imageFile->move(
                        $this->getParameter('blog_directory'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    return new Response("Error al subir la imagen: " . $e->getMessage());
                }

                $post->setImage($newFilename);
            }

            // Quitamos los caracteres especiales del título para crear el slug
            $post->setSlug($slugger->slug($post->getTitle()));

            // Guardamos el usuario que crea el post y los contadores a 0
            $post->setPostUser($this->getUser());
            $post->setNumLikes(0);
            $post->setNumComments(0);

            $entityManager = $doctrine->getManager();
            $entityManager->persist($post);
            $entityManager->flush();

            return $this->redirectToRoute('single_post', ['slug' => $post->getSlug()]);
        }

        return $this->render('blog/new_post.html.twig', [
            'form' => $form->createView(),
        ]);
    }

}

