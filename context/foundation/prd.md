---
project: "Koszykomat"
version: 2
status: draft
created: 2026-06-05
updated: 2026-08-30
context_type: greenfield
product_type: web-app
target_scale:
  users: medium
  qps: "# TODO: target_scale.qps — see Open Questions"
  data_volume: "# TODO: target_scale.data_volume — see Open Questions"
timeline_budget:
  mvp_weeks: 3
  hard_deadline: null
  after_hours_only: true
---

# PRD: Koszykomat

## Vision & Problem Statement

Klienci sieci dyskontów w Polsce, planując zakupy i przeglądając budżet domowy, nie są w stanie ustalić, gdzie ich koszyk będzie realnie tańszy — przekaz reklamowy Lidla i Biedronki jest sprzeczny, a rzeczywiste ceny są uwięzione w gazetkach (PDF/JPG/materiały marketingowe) w formie nieporównywalnej 1:1. Dziś radzą sobie ręcznym porównywaniem gazetek obu sieci, co jest czasochłonne i nie daje odpowiedzi na poziomie całego koszyka zakupów.

Insight: istniejące serwisy (agregatory gazetek) to wygodne zbiory obrazków — w najlepszym przypadku pozwalają wyszukać produkt i znaleźć gazetkę, w której się pojawił. Ten system robi co innego: strukturyzuje ceny do porównywalnej postaci i rozumie mechanikę promocji (1+1, drugi produkt za złotówkę), w tym jej ukryty koszt — cena warunkowa wymagająca zakupu np. dwóch sztuk może być wadą, bo zmusza do ponadnormatywnego, celowego zakupu. Podgląd samych gazetek nie jest funkcją core (lub nie będzie nią przynajmniej w pierwszej wersji).

## User & Persona

Persona pierwotna: szeroka publika w Polsce — osoby świadomie zarządzające budżetem domowym, klienci Lidla i Biedronki, którzy przedkładają realne ceny nad przekaz reklamowy. Ból odczuwają najmocniej przy planowaniu zakupów (moment główny), przy przeglądzie budżetu domowego oraz przy kontakcie z reklamami/gazetkami obu sieci. Problem nazwany na podstawie własnego doświadczenia autora i jego znajomych, ale produkt od startu adresowany publicznie.

## Success Criteria

### Primary
- Zalogowany użytkownik tworzy koszyk z 3 produktów (ilość opcjonalna) i otrzymuje poprawne porównanie cen Lidl vs Biedronka z werdyktem „gdzie taniej", z naliczonymi mechanikami promocji (prosta cena promocyjna, 1+1 gratis, drugi produkt za złotówkę/grosz, ceny z kartą lojalnościową, cena jednostkowa warunkowana ilością), na danych odświeżanych automatycznie co noc.

### Secondary
- Użytkownicy zapisują koszyki i wracają porównać je ponownie po odświeżeniu danych (retencja zapisanych koszyków).

### Guardrails
- Werdykt nie kłamie: przy niepełnych lub nieaktualnych danych system komunikuje „brak danych / nie wiem" zamiast pokazywać błędny werdykt. Zaufanie do wyniku jest całym produktem.

## User Stories

### US-01: Użytkownik porównuje koszyk zakupowy

- **Given** zalogowany użytkownik, a system ma aktualne dane cen i promocji obu sieci
- **When** tworzy koszyk z 3 produktów (ilość opcjonalna) i uruchamia porównanie
- **Then** widzi podsumowanie porównania z werdyktem „gdzie taniej" i naliczonymi promocjami

#### Acceptance Criteria
- Werdykt wskazuje tańszą sieć dla całego koszyka albo komunikuje „brak danych" (nigdy błędny werdykt — guardrail)
- Ceny warunkowe (1+1, drugi za zł/gr, cena jednostkowa przy zakupie N sztuk) są naliczane zgodnie z wymaganą ilością sztuk, a wymuszony ponadnormatywny zakup jest widoczny w raporcie
- Produkty dopasowane między sieciami mają zaznaczoną różnicę marki

## Functional Requirements

### Dostęp i konta
- FR-001: Gość może zobaczyć na stronie głównej stałe porównanie przykładowego koszyka (np. typowe zakupy) z werdyktem. Priority: must-have
  > Socrates: Kontrargument uznany: „stałe porównanie pojedynczego produktu nie sprzedaje produktu — wartość to koszyk + promocje".
  > Rozstrzygnięcie: zmieniono treść — stałe porównanie = przykładowy koszyk z werdyktem, jako demo realnej wartości bez logowania.
- FR-002: Gość może zarejestrować się i zalogować przez OAuth. Priority: must-have
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
- FR-006: System może przetworzyć gazetkę w formie graficznej (jeden format źródła na sieć) w ustrukturyzowaną bazę cen i promocji. Priority: must-have
  > Socrates: Rozważono „OCR/vision myli ceny" i „gazetka to złe źródło". Bez kontrargumentu — stoi jak napisany.
- FR-007: System może rozpoznać i poprawnie naliczyć pięć mechanik promocji: prostą cenę promocyjną, 1+1 gratis, drugi produkt za złotówkę/grosz, cenę z kartą lojalnościową oraz cenę jednostkową warunkowaną ilością („cena za 1 opak. przy zakupie N opak."). Priority: must-have
  > Socrates: Rozważono „karta lojalnościowa rozdwaja werdykt" i „4 mechaniki = dużo krawędzi". Bez kontrargumentu — stoi jak napisany.
  > Rozszerzenie 2026-08-30 (dowód z danych, nie z dyskusji): pierwsza ingestia prawdziwej gazetki Lidla pokazała, że **cena jednostkowa warunkowana ilością jest tam mechaniką dominującą** — fraza „przy zakupie N" występuje 94 razy w jednej gazetce, częściej niż „gratis" (25) i „za grosz" (8) razem wzięte. Czterema pierwotnymi mechanikami nie da się jej zapisać: nie jest ani prostą obniżką (cena zależy od ilości), ani ceną za kolejną sztukę (wszystkie sztuki kosztują tyle samo). Bez piątej mechaniki system musiałby zwracać „brak danych" dla większości realnych ofert Lidla — czyli guardrail działałby poprawnie, a produkt nie pokazywałby nic.
- FR-008: System może dopasować odpowiadające sobie produkty między sieciami; raport zawsze jawnie pokazuje, co z czym sparowano (marka, gramatura). Priority: must-have
  > Socrates: Kontrargument uznany: „automat może dopasować nieporównywalne (gramatura, marka własna vs brandowa) — fałszywe porównania".
  > Rozstrzygnięcie: pary jawne w raporcie — użytkownik widzi podstawę werdyktu i sam ocenia porównywalność.

### Operacje
- FR-009: System automatycznie odświeża dane raz w nocy; wpisy z gazetek mają datę wygaśnięcia gazetki. Priority: must-have
  > Socrates: Kontrargument rozważony: „gazetki są tygodniowe — nocny cron to nadmiar; cichy fail = stare dane".
  > Rozstrzygnięcie (własne użytkownika): wpisy w bazie dostają datę wygaśnięcia gazetki, co rozwiązuje nieaktualność; reszta zostaje.

## Non-Functional Requirements

- Mobile-first: cały przepływ (logowanie → koszyk → raport porównania) jest w pełni użyteczny na telefonie.
- Responsywność porównania: wynik porównania koszyka pojawia się w czasie odczuwalnym jako krótki (< 2 s), a przy dłuższym przetwarzaniu użytkownik widzi ciągły, widoczny postęp.
- Transparentność świeżości danych: każda cena w raporcie ma widoczny okres ważności (od–do gazetki) — użytkownik zawsze wie, na jak aktualnych danych stoi werdykt.
- Prywatność koszyków: zapisane koszyki są widoczne wyłącznie dla właściciela konta.

## Business Logic

System rozstrzyga, w której sieci dany koszyk zakupowy jest realnie tańszy, naliczając rzeczywisty koszt mechanik promocyjnych (w tym wymuszonych zakupów wielosztukowych) i jawnie dopasowując odpowiedniki produktów między sieciami.

Reguła konsumuje: koszyk użytkownika (produkty + ilości, domyślnie 1) oraz aktualne, ustrukturyzowane dane o cenach i promocjach obu sieci (z datą wygaśnięcia gazetki). Promocje warunkowe są naliczane od faktycznej ilości sztuk, a wymuszony ponadnormatywny zakup jest traktowany jako koszt i widoczny w wyniku — cena „po promocji" nie jest brana naiwnie.

Cena jednostkowa warunkowana ilością rządzi się tym samym prawem, tylko ostrzej: obniżona cena obowiązuje **wyłącznie** przy zakupie co najmniej N sztuk, więc przy mniejszej ilości reguła liczy cenę regularną, a przy ilości nie będącej wielokrotnością N — cenę obniżoną za pełne wielokrotności i regularną za resztę. Kupujący jednego jogurtu nie dostaje ceny za sześć, a raport pokazuje, ile sztuk trzeba było dołożyć, żeby promocja w ogóle zadziałała.

Wyjściem jest werdykt „gdzie taniej" dla całego koszyka wraz z raportem porównania: jawne pary dopasowanych produktów (marka, gramatura) i naliczone promocje. Gdy dane są niepełne lub wygasłe, reguła zwraca „brak danych" zamiast werdyktu.

Użytkownik spotyka regułę w raporcie po uruchomieniu porównania koszyka, a gość — w stałym porównaniu przykładowego koszyka na stronie głównej.

## Access Control

Dwa poziomy dostępu w MVP:

- **Gość (niezalogowany):** widzi wyłącznie stałe porównanie przykładowego koszyka z werdyktem na stronie głównej (FR-001). Próba wejścia w pełny raport prowadzi do logowania/rejestracji.
- **Użytkownik (zalogowany):** pełny raport porównania, generowanie porównania całego koszyka zakupów, zapisywanie koszyków per konto. Rejestracja otwarta (produkt publiczny). Logowanie: wyłącznie OAuth — bez email+hasło w MVP (OAuth nie wymaga wysyłania e-maili).

Poza MVP: rola admina i panel administracyjny. Odświeżanie danych odbywa się automatycznie, poza interfejsem produktu; zarządzanie użytkownikami odłożone do v2. Korekta sparsowanych danych — poza MVP.

## Non-Goals

- **Więcej sieci niż Lidl i Biedronka** — MVP obsługuje wyłącznie te dwie sieci; kolejne dopiero w v2 (nazwa i architektura pozostają uniwersalne).
- **Zaawansowany matching produktów** — żadnego porównywania jakości, zamienników ani przeliczeń gramatur; tylko proste odpowiedniki z jawnym oznaczeniem różnic (marka, gramatura).
- **Ceny lokalne per sklep** — jedna cena ogólnopolska z gazetki; bez różnic między sklepami tej samej sieci i bez geolokalizacji.
- **Historia cen i trendy** — tylko bieżące dane z aktualnych gazetek; bez wykresów historycznych, śledzenia zmian cen i alertów.
- **Podgląd stron gazetek** — odłożony do v2; raport opiera się na danych ustrukturyzowanych, nie na obrazkach.
- **Panel administracyjny** — bez UI admina w MVP; operacje (odświeżanie danych, zarządzanie) wykonywane poza interfejsem produktu; zarządzanie użytkownikami w v2.
- **Logowanie email+hasło** — wyłącznie OAuth w MVP (bez wysyłania e-maili).
- **Odświeżanie na żądanie w interfejsie** — odświeżanie wyłącznie automatyczne (raz w nocy); ręczne wyzwalanie pozostaje czynnością operacyjną poza interfejsem produktu.
- **Kolejne formaty źródeł (API, PDF)** — MVP przetwarza wyłącznie jeden format źródła na sieć (format graficzny); pozostałe formaty źródeł poza MVP.

## Open Questions

1. **Jaki rząd wielkości ruchu (target_scale.qps)?** — Owner: użytkownik. Potrzebne najpóźniej przed doborem stacku. Block: no (PRD kompletny; pole frontmatter oznaczone TODO).
2. **Jaki rząd wielkości danych (target_scale.data_volume)?** — np. liczba produktów/wpisów cenowych z gazetek tygodniowo dla dwóch sieci. Owner: użytkownik. Potrzebne najpóźniej przed doborem stacku. Block: no (PRD kompletny; pole frontmatter oznaczone TODO).
