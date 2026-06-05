---
project: "Koszykomat"
context_type: greenfield
created: 2026-06-05
updated: 2026-06-05
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  gray_areas_resolved:
    - topic: "nazwa projektu"
      decision: "Koszykomat — uniwersalna, bez nazw sieci; wybrana z burzy mózgów na prośbę użytkownika"
    - topic: "dopasowanie produktów"
      decision: "proste dopasowanie odpowiedników JEST w MVP (z sygnalizacją różnicy marki); zaawansowany matching — poza MVP"
    - topic: "auth"
      decision: "wyłącznie OAuth (zmiana w Fazie 3 — bez email+hasło); rejestracja otwarta"
    - topic: "role"
      decision: "gość / użytkownik; admin wypadł z MVP w Fazie 3 (operacje przez CLI/joby)"
    - topic: "scope-down MVP"
      decision: "jeden format źródła na sieć (np. vision/JPG); tylko OAuth; bez podglądu gazetek; bez panelu admina; odświeżanie tylko CLI/cron"
    - topic: "mechaniki promocji w MVP"
      decision: "prosta cena promocyjna; 1+1 gratis; drugi za złotówkę/grosz; ceny z kartą lojalnościową"
    - topic: "moment bólu"
      decision: "planowanie zakupów (główny), przegląd budżetu, kontakt z reklamą"
    - topic: "koszt dziś"
      decision: "ręczne porównywanie gazetek — czasochłonne, bez odpowiedzi na poziomie koszyka"
    - topic: "kategoria bólu"
      decision: "dane uwięzione w gazetkach + paraliż decyzyjny"
    - topic: "zasięg persony"
      decision: "szeroka publika w Polsce — produkt publiczny od startu"
  frs_drafted: 9
  quality_check_status: accepted
---

# Shape Notes

## Seed idea (verbatim, from idea.md)

> W Polsce trwa ikoniczna wojna pomiędzy siecią marketów Lidl i Biedronka. Reklamy każdorazowo przekazują niejako, że sieć X jest tańsza niż Y.
> Czas z tym skończyć i wreszcie odpowiedzieć na te odwieczne pytanie.
>
> Ideą jest stworzenie prostej strony (aplikacji) www (mobile-first), która będzie się składała z:
>
> - Części serwerowej (backend), która będzie odpowiedzialna za pobieranie, parsowanie, analizowanie na bieżąco gazetek lub innych oficjalnych źródeł na temat promocji i cen w danej sieci.
> - Obszaru użytkownika (f-end), gdzie użytkownicy będą mogli porównać ceny konkretnych produktów lub koszyków.
>
> Funkcje:
>
> - System musi potrafić przetworzyć dowolny format, w tym także gazetkę w formie graficznej w ustrukturyzowaną bazę danych.
> - Musi dobrze rozumieć charakter promocji, zwłaszcza promocji typu 1+1, drugi produkt za złotówkę.
> - Docelowo ma mieć możliwość obsługi większej ilości sieci - Lidl i Biedronka to zakres MVP.
> - System musi mieć bieżące dane, zakładamy odświeżanie na żądanie i automatyczne (cron) raz w nocy.
> - Strona główna będzie wyświetlała tylko pojedyncze, stałe porównanie cen. Dokładny raport będzie dostępny po zalogowaniu.
> - Użytkownicy będą mogli generować raport porównania całego koszyka zakupów.
> - Koszyk będzie mógł być zapisany per użytkownik.
>
> Poza MVP:
> - Uwzględniamy proste porównanie (mleko X = mleko Y), zwracamy tylko uwagę na różnice w marce porównywanych produktów.
> - Pod względem programistycznym uwzględniamy wiele typów źródeł (API, PDF, JPG), ale nie implementujemy jeszcze providerów.
>
> Nazwa projektu: nieokreślona. O ile w wersji I będzie skupiać się tylko na tych dwóch sieciach, nazwa musi być bardziej uniwersalna.

## Vision & Problem Statement

Klienci sieci dyskontów w Polsce, planując zakupy i przeglądając budżet domowy, nie są w stanie ustalić, gdzie ich koszyk będzie realnie tańszy — przekaz reklamowy Lidla i Biedronki jest sprzeczny, a rzeczywiste ceny są uwięzione w gazetkach (PDF/JPG/materiały marketingowe) w formie nieporównywalnej 1:1. Dziś radzą sobie ręcznym porównywaniem gazetek obu sieci, co jest czasochłonne i nie daje odpowiedzi na poziomie całego koszyka zakupów.

Insight: istniejące serwisy (agregatory gazetek) to wygodne zbiory obrazków — w najlepszym przypadku pozwalają wyszukać produkt i znaleźć gazetkę, w której się pojawił. Ten system robi co innego: strukturyzuje ceny do porównywalnej postaci i rozumie mechanikę promocji (1+1, drugi produkt za złotówkę), w tym jej ukryty koszt — cena warunkowa wymagająca zakupu np. dwóch sztuk może być wadą, bo zmusza do ponadnormatywnego, celowego zakupu. Podgląd samych gazetek nie jest funkcją core (lub nie będzie nią przynajmniej w pierwszej wersji).

## User & Persona

Persona pierwotna: szeroka publika w Polsce — osoby świadomie zarządzające budżetem domowym, klienci Lidla i Biedronki, którzy przedkładają realne ceny nad przekaz reklamowy. Ból odczuwają najmocniej przy planowaniu zakupów (moment główny), przy przeglądzie budżetu domowego oraz przy kontakcie z reklamami/gazetkami obu sieci. Problem nazwany na podstawie własnego doświadczenia autora i jego znajomych, ale produkt od startu adresowany publicznie.

## Access Control

Dwa poziomy dostępu w MVP:

- **Gość (niezalogowany):** widzi wyłącznie stałe porównanie przykładowego koszyka z werdyktem na stronie głównej (doprecyzowane w rundzie sokratejskiej, FR-001). Próba wejścia w pełny raport prowadzi do logowania/rejestracji.
- **Użytkownik (zalogowany):** pełny raport porównania, generowanie porównania całego koszyka zakupów, zapisywanie koszyków per konto. Rejestracja otwarta (produkt publiczny). Logowanie: wyłącznie OAuth (np. Google) — bez email+hasło w MVP (decyzja Fazy 3: OAuth nie wymaga wysyłania e-maili).

Poza MVP (zmiana z Fazy 3): rola admina i panel administracyjny. Odświeżanie danych wyzwalane przez CLI/joby (cron + ręczne uruchomienie z linii poleceń); zarządzanie użytkownikami odłożone do v2. Korekta sparsowanych danych — poza MVP.

## Success Criteria

### Primary
- Zalogowany użytkownik tworzy koszyk z 3 produktów (ilość opcjonalna) i otrzymuje poprawne porównanie cen Lidl vs Biedronka z werdyktem „gdzie taniej", z naliczonymi mechanikami promocji (prosta cena promocyjna, 1+1 gratis, drugi produkt za złotówkę/grosz, ceny z kartą lojalnościową), na danych odświeżanych automatycznie co noc.

### Secondary
- Użytkownicy zapisują koszyki i wracają porównać je ponownie po odświeżeniu danych (retencja zapisanych koszyków).

### Guardrails
- Werdykt nie kłamie: przy niepełnych lub nieaktualnych danych system komunikuje „brak danych / nie wiem" zamiast pokazywać błędny werdykt. Zaufanie do wyniku jest całym produktem.

## Timeline budget (seed)

- `timeline_budget.mvp_weeks: 3` — szacunek użytkownika po scope-down.
- Scope-down przyjęty w Fazie 3: jeden format źródła na sieć (dwie sieci mogą dzielić ten sam parser, np. vision po plikach JPG); tylko OAuth (bez email+hasło); bez podglądu stron gazetek (v2); bez panelu admina (operacje przez CLI/joby); odświeżanie na żądanie tylko z CLI; mechaniki promocji zawężone do czterech wymienionych.

## Functional Requirements

### Dostęp i konta
- FR-001: Gość może zobaczyć na stronie głównej stałe porównanie przykładowego koszyka (np. typowe zakupy) z werdyktem. Priority: must-have
  > Socrates: Kontrargument uznany: „stałe porównanie pojedynczego produktu nie sprzedaje produktu — wartość to koszyk + promocje".
  > Rozstrzygnięcie: zmieniono treść — stałe porównanie = przykładowy koszyk z werdyktem, jako demo realnej wartości bez logowania.
- FR-002: Gość może zarejestrować się i zalogować przez OAuth (np. Google). Priority: must-have
  > Socrates: Rozważono „OAuth wyklucza część person" i „logowanie przed wartością". Bez kontrargumentu — stoi jak napisany.

### Koszyk i porównanie
- FR-003: Użytkownik może utworzyć koszyk zakupowy z produktów; ilość sztuk opcjonalna w UI, domyślnie 1 — promocje warunkowe naliczane od faktycznej ilości. Priority: must-have
  > Socrates: Kontrargument uznany: „promocje warunkowe (1+1, drugi za zł) wymagają ilości — opcjonalność kłóci się z sercem produktu".
  > Rozstrzygnięcie: domyślna ilość = 1; system zawsze ma ilość do naliczenia mechanik.
- FR-004: Użytkownik może wygenerować raport porównania koszyka Lidl vs Biedronka z werdyktem „gdzie taniej", z naliczonymi promocjami. Priority: must-have
  > Socrates: Rozważono „gazetki pokrywają tylko promocje" i „werdykt 0-1 ukrywa niuanse". Bez kontrargumentu — stoi jak napisany.
- FR-005: Użytkownik może zapisać koszyk na swoim koncie i wrócić do niego później (ponowne porównanie po odświeżeniu danych). Priority: must-have
  > Socrates: Rozważono „koszyk wygasa z gazetką" i „konta + RODO za wcześnie". Bez kontrargumentu — stoi jak napisany.

### Ingestia i analiza danych (system)
- FR-006: System może przetworzyć gazetkę w formie graficznej (jeden format źródła na sieć, np. vision po JPG) w ustrukturyzowaną bazę cen i promocji. Priority: must-have
  > Socrates: Rozważono „OCR/vision myli ceny" i „gazetka to złe źródło". Bez kontrargumentu — stoi jak napisany.
- FR-007: System może rozpoznać i poprawnie naliczyć cztery mechaniki promocji: prostą cenę promocyjną, 1+1 gratis, drugi produkt za złotówkę/grosz, cenę z kartą lojalnościową. Priority: must-have
  > Socrates: Rozważono „karta lojalnościowa rozdwaja werdykt" i „4 mechaniki = dużo krawędzi". Bez kontrargumentu — stoi jak napisany.
- FR-008: System może dopasować odpowiadające sobie produkty między sieciami; raport zawsze jawnie pokazuje, co z czym sparowano (marka, gramatura). Priority: must-have
  > Socrates: Kontrargument uznany: „automat może dopasować nieporównywalne (gramatura, marka własna vs brandowa) — fałszywe porównania".
  > Rozstrzygnięcie: pary jawne w raporcie — użytkownik widzi podstawę werdyktu i sam ocenia porównywalność.

### Operacje
- FR-009: System automatycznie odświeża dane raz w nocy (cron); wpisy z gazetek mają datę wygaśnięcia gazetki. Priority: must-have
  > Socrates: Kontrargument rozważony: „gazetki są tygodniowe — nocny cron to nadmiar; cichy fail = stare dane".
  > Rozstrzygnięcie (własne użytkownika): wpisy w bazie dostają datę wygaśnięcia gazetki, co rozwiązuje nieaktualność; reszta zostaje.

(Usunięto FR-010 „Operator może wyzwolić odświeżenie danych z CLI" — rozstrzygnięcie rundy sokratejskiej: to narzędzie deweloperskie, nie wymaganie produktowe; przeniesione do `## Forward: technical-roadmap`.)

## User Stories

### US-01: Użytkownik porównuje koszyk zakupowy

- **Given** zalogowany użytkownik, a system ma aktualne dane cen i promocji obu sieci
- **When** tworzy koszyk z 3 produktów (ilość opcjonalna) i uruchamia porównanie
- **Then** widzi podsumowanie porównania z werdyktem „gdzie taniej" i naliczonymi promocjami

#### Acceptance Criteria
- Werdykt wskazuje tańszą sieć dla całego koszyka albo komunikuje „brak danych" (nigdy błędny werdykt — guardrail)
- Ceny warunkowe (1+1, drugi za zł/gr) są naliczane zgodnie z wymaganą ilością sztuk, a wymuszony ponadnormatywny zakup jest widoczny w raporcie
- Produkty dopasowane między sieciami mają zaznaczoną różnicę marki

## Forward: technical-roadmap

- Ręczne wyzwalanie odświeżenia danych z CLI (dawny FR-010) — narzędzie deweloperskie/operacyjne, nie wymaganie produktowe; i tak powstanie przy implementacji jobów/kolejek.
- Z seeda: architektura ingestii projektowana pod wiele typów źródeł (API, PDF, JPG), ale w MVP implementowany jest tylko jeden provider (format graficzny); kolejne providery bez zmiany architektury.
- Z seeda: docelowo obsługa większej liczby sieci — Lidl i Biedronka to zakres MVP.

## Business Logic

System rozstrzyga, w której sieci dany koszyk zakupowy jest realnie tańszy, naliczając rzeczywisty koszt mechanik promocyjnych (w tym wymuszonych zakupów wielosztukowych) i jawnie dopasowując odpowiedniki produktów między sieciami.

Reguła konsumuje: koszyk użytkownika (produkty + ilości, domyślnie 1) oraz aktualne, ustrukturyzowane dane o cenach i promocjach obu sieci (z datą wygaśnięcia gazetki). Promocje warunkowe są naliczane od faktycznej ilości sztuk, a wymuszony ponadnormatywny zakup jest traktowany jako koszt i widoczny w wyniku — cena „po promocji" nie jest brana naiwnie.

Wyjściem jest werdykt „gdzie taniej" dla całego koszyka wraz z raportem porównania: jawne pary dopasowanych produktów (marka, gramatura) i naliczone promocje. Gdy dane są niepełne lub wygasłe, reguła zwraca „brak danych" zamiast werdyktu.

Użytkownik spotyka regułę w raporcie po uruchomieniu porównania koszyka, a gość — w stałym porównaniu przykładowego koszyka na stronie głównej.

## Non-Functional Requirements

- Mobile-first: cały przepływ (logowanie → koszyk → raport porównania) jest w pełni użyteczny na telefonie.
- Responsywność porównania: wynik porównania koszyka pojawia się w czasie odczuwalnym jako krótki (< 2 s), a przy dłuższym przetwarzaniu użytkownik widzi ciągły, widoczny postęp.
- Transparentność świeżości danych: każda cena w raporcie ma widoczny okres ważności (od–do gazetki) — użytkownik zawsze wie, na jak aktualnych danych stoi werdykt.
- Prywatność koszyków: zapisane koszyki są widoczne wyłącznie dla właściciela konta.

## Product framing (frontmatter seed)

- `product_type: web-app` — prosta strona/aplikacja www, mobile-first (z seeda, potwierdzone).
- `target_scale.users: medium` — dziesiątki do ~setki użytkowników po starcie. Sonda 100×: reguła domenowa bez zmian (decyzja użytkownika).
- `timeline_budget`: `mvp_weeks: 3`, `hard_deadline: null`, `after_hours_only: true`.

## Non-Goals

- **Więcej sieci niż Lidl i Biedronka** — MVP obsługuje wyłącznie te dwie sieci; kolejne dopiero w v2 (nazwa i architektura pozostają uniwersalne).
- **Zaawansowany matching produktów** — żadnego porównywania jakości, zamienników ani przeliczeń gramatur; tylko proste odpowiedniki z jawnym oznaczeniem różnic (marka, gramatura).
- **Ceny lokalne per sklep** — jedna cena ogólnopolska z gazetki; bez różnic między sklepami tej samej sieci i bez geolokalizacji.
- **Historia cen i trendy** — tylko bieżące dane z aktualnych gazetek; bez wykresów historycznych, śledzenia zmian cen i alertów.
- **Podgląd stron gazetek** — odłożony do v2 (decyzja Fazy 3); raport opiera się na danych ustrukturyzowanych, nie na obrazkach.
- **Panel administracyjny** — bez UI admina w MVP; operacje (odświeżanie, zarządzanie) przez CLI/joby; zarządzanie użytkownikami w v2.
- **Logowanie email+hasło** — wyłącznie OAuth w MVP (bez wysyłania e-maili).
- **Odświeżanie na żądanie w interfejsie** — odświeżanie tylko automatyczne (cron) i z CLI.
- **Kolejne formaty źródeł (API, PDF)** — architektura ingestii projektowana pod wiele typów źródeł, ale w MVP zaimplementowany tylko jeden provider (format graficzny).

## Quality cross-check

Cross-check z 2026-06-05: wszystkie elementy obecne (Access Control, Business Logic — reguła jednozdaniowa, artefakty projektu, timeline-cost ≤ 3 tyg. po świadomym scope-down, Non-Goals — 9 pozycji). Brak luk; `quality_check_status: accepted`. Brak pozycji do przeniesienia do Open Questions w PRD.
